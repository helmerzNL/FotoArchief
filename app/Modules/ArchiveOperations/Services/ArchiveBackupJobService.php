<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Jobs\RebuildDerivativesJob;
use App\Modules\ArchiveOperations\Jobs\RunArchiveBackupJob;
use App\Modules\ArchiveOperations\Models\BackupJob;
use App\Modules\ArchiveOperations\Support\StoredZipWriter;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;
use ZipArchive;

/**
 * The in-app archive backup (App-Guidance shared/guidelines/backup-restore.md) as server jobs.
 *
 * Once a file is up, the work belongs to the server: an export and a restore are rows in
 * backup_jobs, carried out by RunArchiveBackupJob on the ingest queue in bounded steps (the
 * worker's time limit is two minutes), and watched by the screen. Leaving or reloading the page
 * stops nothing. One job runs at a time; a running job that has not moved for fifteen minutes is
 * reported as interrupted, which is safe because the swap is one transaction.
 */
class ArchiveBackupJobService
{
    public const string TOKEN_PATTERN = '/^[0-9a-f]{32}$/';

    public const string AUTO_PATTERN = '/^fotoarchief-auto-(\d{4})-(\d{2})-(\d{2})-(\d{2})(\d{2})(\d{2})Z\.(zip|json)$/';

    public function __construct(
        private readonly ArchiveBackupExporter $exporter,
        private readonly ArchiveBackupRestorer $restorer,
        private readonly OperationRunService $operations,
    ) {}

    // ----- starting, watching, dismissing -------------------------------------------------

    public function startExport(User $user, bool $withFiles): BackupJob
    {
        return $this->start(BackupJob::KIND_EXPORT, $user, $withFiles, []);
    }

    public function startRestore(User $user, string $token): BackupJob
    {
        $path = $this->stagedPath($token);
        $meta = $this->stagedMeta($token);
        if (! is_file($path) || ($meta['checked'] ?? false) !== true) {
            throw new ArchiveBackupRefused('operations.backup.errors.stage_gone');
        }

        return $this->start(BackupJob::KIND_RESTORE, $user, (bool) ($meta['includes_files'] ?? false), ['token' => $token]);
    }

    /** The running job, or the last finished one nobody dismissed (exports and restores). */
    public function latest(): ?BackupJob
    {
        $this->expireStale();

        return BackupJob::query()
            ->whereIn('kind', [BackupJob::KIND_EXPORT, BackupJob::KIND_RESTORE])
            ->where(fn ($query) => $query->where('status', BackupJob::STATUS_RUNNING)->orWhereNull('dismissed_at'))
            ->orderByDesc('created_at')
            ->first();
    }

    public function dismiss(BackupJob $job): void
    {
        if (! $job->isRunning()) {
            $job->forceFill(['dismissed_at' => now()])->save();
        }
    }

    /** @return array<string, mixed> */
    public function present(BackupJob $job): array
    {
        $error = null;
        if ($job->error_key !== null) {
            $params = $job->error_params ?? [];
            if (isset($params['reason_key']) && is_string($params['reason_key'])) {
                $params['reason'] = __($params['reason_key'], is_array($params['reason_params'] ?? null) ? $params['reason_params'] : []);
                unset($params['reason_key'], $params['reason_params']);
            }
            $error = __($job->error_key, array_map('strval', $params));
        }

        return [
            'id' => $job->id,
            'kind' => $job->kind,
            'status' => $job->status,
            'phase' => $job->phase,
            'step' => $job->step,
            'steps' => $job->steps,
            'includesFiles' => $job->includes_files,
            'error' => $error,
            'summary' => $job->summary,
            'resultName' => $job->result_name,
            'resultBytes' => $job->result_bytes,
            'checkingSeconds' => $job->checking_seconds,
            'replacingSeconds' => $job->replacing_seconds,
            'downloadUrl' => $job->kind === BackupJob::KIND_EXPORT && $job->status === BackupJob::STATUS_DONE && is_file($this->resultPath($job))
                ? route('admin.operations.backup.download', $job, false)
                : null,
        ];
    }

    public function resultPath(BackupJob $job): string
    {
        return $this->staging().'/export-'.$job->id.($job->includes_files ? '.zip' : '.json');
    }

    // ----- staging a file for a restore -----------------------------------------------------

    /**
     * Starts a staged upload of $size bytes. The browser then sends the file in chunks, each
     * well under PHP's limit for a request body, so no proxy or PHP limit decides how large a
     * backup can be; operations.backup.max_upload_bytes does.
     */
    public function createStage(int $size): string
    {
        $max = (int) config('operations.backup.max_upload_bytes');
        if ($size <= 0) {
            throw new ArchiveBackupRefused('operations.backup.errors.not_a_backup');
        }
        if ($size > $max) {
            throw new ArchiveBackupRefused('operations.backup.errors.upload_too_large', ['max' => $this->megabytes($max)]);
        }
        $free = @disk_free_space($this->staging());
        if (is_float($free) && $free < $size * 1.1) {
            throw new ArchiveBackupRefused('operations.backup.errors.disk_full');
        }
        [$token, $path] = $this->newStage();
        touch($path);
        file_put_contents($path.'.json', json_encode(['size' => $size, 'checked' => false, 'staged_at' => now()->toIso8601String()]));

        return $token;
    }

    /**
     * Appends one chunk at $offset. A chunk that was already received (a retry after a dropped
     * answer) is accepted without writing it twice.
     *
     * @param  resource  $body
     */
    public function appendChunk(string $token, int $offset, $body): int
    {
        $path = $this->stagedPath($token);
        $meta = $this->stagedMeta($token);
        if (! is_file($path) || ! isset($meta['size']) || ($meta['checked'] ?? false) === true) {
            throw new ArchiveBackupRefused('operations.backup.errors.stage_gone');
        }
        clearstatcache(true, $path);
        $current = (int) filesize($path);
        $chunk = stream_get_contents($body, 33_554_433);
        if (! is_string($chunk) || strlen($chunk) > 33_554_432) {
            throw new ArchiveBackupRefused('operations.backup.errors.not_a_backup');
        }
        if ($offset + strlen($chunk) <= $current) {
            return $current;
        }
        if ($offset !== $current || $current + strlen($chunk) > (int) $meta['size']) {
            throw new ArchiveBackupRefused('operations.backup.errors.upload_out_of_order');
        }
        if (file_put_contents($path, $chunk, FILE_APPEND | LOCK_EX) !== strlen($chunk)) {
            throw new ArchiveBackupRefused('operations.backup.errors.disk_full');
        }

        return $current + strlen($chunk);
    }

    /**
     * Checks a completely uploaded file. Nothing in the database changes; a refused file is
     * removed on the spot.
     *
     * @return array<string, mixed>
     */
    public function finishStage(string $token): array
    {
        $path = $this->stagedPath($token);
        $meta = $this->stagedMeta($token);
        clearstatcache(true, $path);
        if (! is_file($path) || (int) filesize($path) !== (int) ($meta['size'] ?? -1)) {
            $this->discardStage($token);
            throw new ArchiveBackupRefused('operations.backup.errors.upload_incomplete');
        }
        try {
            return $this->checkStage($token);
        } catch (Throwable $exception) {
            $this->discardStage($token);
            throw $exception;
        }
    }

    /**
     * Stages a copy of a kept automatic backup; the kept file stays where it is.
     *
     * @return array{token: string, summary: array<string, mixed>}
     */
    public function stageAutomatic(string $name): array
    {
        $source = $this->automaticPath($name);
        [$token, $path] = $this->newStage();
        try {
            if (! @copy($source, $path)) {
                throw new ArchiveBackupRefused('operations.backup.errors.disk_full');
            }

            return ['token' => $token, 'summary' => $this->checkStage($token)];
        } catch (Throwable $exception) {
            $this->discardStage($token);
            throw $exception;
        }
    }

    public function discardStage(string $token): void
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return;
        }
        @unlink($this->stagedPath($token));
        @unlink($this->stagedPath($token).'.json');
    }

    public function stagedPath(string $token): string
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new ArchiveBackupRefused('operations.backup.errors.stage_gone');
        }

        return $this->staging().'/.restore-'.$token;
    }

    // ----- the job's steps --------------------------------------------------------------------

    /** Runs one bounded step; true when another step must follow. */
    public function step(BackupJob $job): bool
    {
        $started = microtime(true);
        $phase = $job->phase;
        try {
            $more = match ($job->kind) {
                BackupJob::KIND_EXPORT, BackupJob::KIND_AUTO => $this->exportStep($job),
                BackupJob::KIND_RESTORE => $this->restoreStep($job),
                default => false,
            };
            // Where the time goes: a restore's checking (everything before the swap), or the
            // whole build of an export.
            if ($phase !== 'replacing') {
                $job->forceFill(['checking_seconds' => round((float) $job->checking_seconds + (microtime(true) - $started), 2)])->save();
            }

            return $more;
        } catch (Throwable $exception) {
            $this->fail($job, $exception);

            return false;
        }
    }

    public function dispatch(BackupJob $job): void
    {
        Queue::connection('ingest')->push(new RunArchiveBackupJob($job->id));
    }

    // ----- automatic backups ----------------------------------------------------------------

    /**
     * The kept automatic backups, newest first.
     *
     * @return list<array{name: string, created_at: CarbonImmutable, bytes: int}>
     */
    public function automaticBackups(): array
    {
        $directory = $this->autoDirectory();
        $list = [];
        foreach (is_dir($directory) ? (scandir($directory) ?: []) : [] as $name) {
            $created = $this->automaticTime($name);
            if ($created !== null && is_file($directory.'/'.$name)) {
                $list[] = ['name' => $name, 'created_at' => $created, 'bytes' => (int) filesize($directory.'/'.$name)];
            }
        }
        usort($list, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $list;
    }

    /** @return array<string, mixed> */
    public function automaticStatus(): array
    {
        $enabled = (bool) config('operations.backup.auto.enabled');
        $backups = $this->automaticBackups();
        $failure = $this->lastAutomaticFailure($backups[0]['created_at'] ?? null);

        return [
            'enabled' => $enabled,
            'interval_hours' => (int) config('operations.backup.auto.interval_hours'),
            'keep' => (int) config('operations.backup.auto.keep'),
            'with_files' => (bool) config('operations.backup.auto.with_files'),
            'next_at' => $enabled ? $this->nextAutomaticAt($backups[0]['created_at'] ?? null, $failure) : null,
            'failure' => $failure === null ? null : [
                'at' => $failure->finished_at,
                'message' => $this->present($failure)['error'],
            ],
            'backups' => $backups,
        ];
    }

    public function automaticPath(string $name): string
    {
        if ($this->automaticTime($name) === null || ! is_file($this->autoDirectory().'/'.$name)) {
            throw new ArchiveBackupRefused('operations.backup.errors.auto_unknown');
        }

        return $this->autoDirectory().'/'.$name;
    }

    /**
     * Starts an automatic backup when one is due. Called by the scheduler every few minutes, on
     * every instance; the lock (which expires) and the check under it make one backup.
     */
    public function runAutomaticIfDue(): ?BackupJob
    {
        if (! (bool) config('operations.backup.auto.enabled')) {
            return null;
        }

        return Cache::lock('operations.backup.auto', 300)->get(function (): ?BackupJob {
            $this->expireStale();
            $this->cleanupAutoTemporaries();
            $backups = $this->automaticBackups();
            $next = $this->nextAutomaticAt($backups[0]['created_at'] ?? null, $this->lastAutomaticFailure($backups[0]['created_at'] ?? null));
            if ($next !== null && $next->isFuture()) {
                return null;
            }
            // Never back up nothing: no backup while nobody can administer the archive.
            if (! $this->hasAdministrator()) {
                return null;
            }
            try {
                return $this->start(BackupJob::KIND_AUTO, null, (bool) config('operations.backup.auto.with_files'), []);
            } catch (ArchiveBackupRefused) {
                return null; // another job (a restore) is running; look again later
            }
        }) ?: null;
    }

    // ----- internals ------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $state
     */
    private function start(string $kind, ?User $user, bool $includesFiles, array $state): BackupJob
    {
        $job = Cache::lock('operations.backup.start', 30)->block(10, function () use ($kind, $user, $includesFiles, $state): BackupJob {
            $this->expireStale();
            $this->cleanupOldFiles();
            if (BackupJob::query()->where('status', BackupJob::STATUS_RUNNING)->exists()) {
                throw new ArchiveBackupRefused('operations.backup.errors.busy');
            }

            return BackupJob::query()->create([
                'kind' => $kind,
                'status' => BackupJob::STATUS_RUNNING,
                'phase' => $kind === BackupJob::KIND_RESTORE ? 'checking' : 'collecting',
                'includes_files' => $includesFiles,
                'state' => $state,
                'requested_by_user_id' => $user?->id,
            ]);
        });
        $this->dispatch($job);

        return $job;
    }

    private function exportStep(BackupJob $job): bool
    {
        $work = $this->workDirectory($job);
        $state = $job->state ?? [];

        if ($job->phase === 'collecting') {
            $result = $this->exporter->writeDocument($work.'/backup.json', $job->includes_files);
            $summary = ['counts' => $result['counts'], 'files' => count($result['files'])];
            if (! $job->includes_files) {
                return $this->finishExport($job, $work.'/backup.json', $summary);
            }
            file_put_contents($work.'/plan.json', json_encode($result['files'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $zip = StoredZipWriter::open($work.'/backup.zip');
            $json = fopen($work.'/backup.json', 'rb');
            if ($json === false) {
                throw new ArchiveBackupRefused('operations.backup.errors.disk_full');
            }
            try {
                $zip->addStream('backup.json', $json, null, true);
            } finally {
                fclose($json);
                $zip->close();
            }
            @unlink($work.'/backup.json');
            $job->forceFill(['phase' => 'files', 'step' => 0, 'steps' => count($result['files']), 'summary' => $summary, 'state' => [...$state, 'cursor' => 0]])->save();

            return true;
        }

        /** @var list<array{id: string, archive_path: string, disk: string, key: string, sha256: string, byte_size: int}> $files */
        $files = json_decode((string) file_get_contents($work.'/plan.json'), true, 512, JSON_THROW_ON_ERROR);
        $zip = StoredZipWriter::open($work.'/backup.zip');
        // A repeated step continues after the last original that is completely in the ZIP
        // (the first entry is backup.json).
        $cursor = max(0, $zip->count() - 1);
        $deadline = microtime(true) + (int) config('operations.backup.step_seconds');
        try {
            while ($cursor < count($files) && microtime(true) < $deadline) {
                $this->exporter->addOriginal($zip, $files[$cursor]);
                $cursor++;
                if ($cursor % 10 === 0) {
                    $job->forceFill(['step' => $cursor])->save();
                }
            }
            if ($cursor >= count($files)) {
                $zip->finish();

                return $this->finishExport($job, $work.'/backup.zip', $job->summary ?? []);
            }
        } finally {
            $zip->close();
        }
        $job->forceFill(['step' => $cursor, 'state' => [...$state, 'cursor' => $cursor]])->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function finishExport(BackupJob $job, string $built, array $summary): bool
    {
        $bytes = (int) filesize($built);
        if ($job->kind === BackupJob::KIND_AUTO) {
            $name = 'fotoarchief-auto-'.now('UTC')->format('Y-m-d-His').'Z'.($job->includes_files ? '.zip' : '.json');
            $directory = $this->autoDirectory();
            File::ensureDirectoryExists($directory, 0750);
            // Write under a temporary name, flush, rename; only then remove the oldest.
            $temporary = $directory.'/.tmp-'.$name;
            if (! @copy($built, $temporary)) {
                @unlink($temporary);
                throw new ArchiveBackupRefused('operations.backup.errors.disk_full');
            }
            $handle = fopen($temporary, 'rb');
            if ($handle !== false) {
                fsync($handle);
                fclose($handle);
            }
            rename($temporary, $directory.'/'.$name);
            $this->rotateAutomatic();
        } else {
            $name = 'fotoarchief-backup-'.now()->format('Y-m-d-His').($job->includes_files ? '.zip' : '.json');
            rename($built, $this->resultPath($job));
        }
        File::deleteDirectory($this->workDirectory($job));
        $job->forceFill([
            'status' => BackupJob::STATUS_DONE,
            'phase' => null,
            'step' => $job->steps,
            'summary' => $summary,
            'result_name' => $name,
            'result_bytes' => $bytes,
            'finished_at' => now(),
        ])->save();
        Log::info('operations.backup.export_done', ['job' => $job->id, 'kind' => $job->kind, 'bytes' => $bytes, 'seconds' => $job->checking_seconds]);

        return false;
    }

    private function restoreStep(BackupJob $job): bool
    {
        $work = $this->workDirectory($job);
        $state = $job->state ?? [];
        $token = (string) ($state['token'] ?? '');
        $path = $this->stagedPath($token);
        if (! is_file($path)) {
            throw new ArchiveBackupRefused('operations.backup.errors.stage_gone');
        }

        if ($job->phase === 'checking') {
            // The check is repeated: the file on disk is not trusted to be the one checked earlier.
            $document = $this->restorer->read($path);
            $summary = $this->restorer->check($document, $path);
            $plan = [];
            foreach ($document['sections']['asset_files'] ?? [] as $row) {
                $plan[] = ['row' => array_intersect_key($row, array_flip(['id', 'sha256', 'byte_size', 'storage_disk', 'storage_key', 'original_filename', 'derivatives'])), 'entry' => $document['files'][(string) $row['id']] ?? null];
            }
            unset($document);
            file_put_contents($work.'/plan.json', json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            @unlink($work.'/placements.jsonl');
            $job->forceFill(['phase' => 'files', 'step' => 0, 'steps' => count($plan), 'summary' => $summary, 'state' => [...$state, 'cursor' => 0]])->save();

            return true;
        }

        if ($job->phase === 'files') {
            $plan = json_decode((string) file_get_contents($work.'/plan.json'), true, 512, JSON_THROW_ON_ERROR);
            $done = $this->placements($job);
            $cursor = count($done);
            $deadline = microtime(true) + (int) config('operations.backup.step_seconds');
            $zip = null;
            if ($job->includes_files) {
                $zip = new ZipArchive;
                if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                    throw new ArchiveBackupRefused('operations.backup.errors.not_a_backup');
                }
            }
            try {
                while ($cursor < count($plan) && microtime(true) < $deadline) {
                    $item = $plan[$cursor];
                    $placement = $zip !== null
                        ? $this->restorer->placeFromArchive($zip, $item['row'], (array) $item['entry'])
                        : $this->restorer->placeInStorage($item['row']);
                    file_put_contents($work.'/placements.jsonl', json_encode(['id' => (string) $item['row']['id'], ...$placement], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
                    $cursor++;
                    if ($cursor % 10 === 0) {
                        $job->forceFill(['step' => $cursor])->save();
                    }
                }
            } finally {
                $zip?->close();
            }
            $finished = $cursor >= count($plan);
            $job->forceFill([
                'step' => $finished ? 0 : $cursor,
                'phase' => $finished ? 'replacing' : 'files',
                'steps' => $finished ? array_sum((array) ($job->summary['counts'] ?? [])) : $job->steps,
            ])->save();

            return true;
        }

        // Replacing: read and check once more, then swap in one transaction.
        $started = microtime(true);
        $document = $this->restorer->read($path);
        $this->restorer->check($document, $path);
        $placements = $this->placements($job);
        $actor = User::query()->find($job->requested_by_user_id);
        $result = $this->restorer->replace(
            $document,
            $placements,
            $job,
            $actor instanceof User ? $actor : null,
            $this->progressWriter($job),
            function (BackupJob $job) use ($started): void {
                DB::table('backup_jobs')->where('id', $job->id)->update([
                    'status' => BackupJob::STATUS_DONE,
                    'phase' => null,
                    'step' => $job->steps,
                    'replacing_seconds' => round(microtime(true) - $started, 2),
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
            },
        );
        $job->refresh();
        $this->discardStage($token);
        File::deleteDirectory($work);
        if ($result['rebuild'] !== [] && $actor instanceof User) {
            $this->operations->dispatchRun(RebuildDerivativesJob::class, RebuildDerivativesJob::TYPE, $actor, [], count($result['rebuild']));
        }
        Log::info('operations.backup.restore_done', ['job' => $job->id, 'checking_seconds' => $job->checking_seconds, 'replacing_seconds' => $job->replacing_seconds, 'rebuild' => count($result['rebuild'])]);

        return false;
    }

    /**
     * Writes progress during the replacing transaction through a second connection, so the
     * screen can see it before the commit. SQLite (tests) has no second connection to the same
     * data and skips it.
     *
     * @return Closure(int, int): void
     */
    private function progressWriter(BackupJob $job): Closure
    {
        $default = (string) config('database.default');
        if (DB::connection($default)->getDriverName() !== 'pgsql') {
            return static function (int $done, int $total): void {};
        }
        config(['database.connections.backup_progress' => config('database.connections.'.$default)]);
        $last = 0.0;

        return static function (int $done, int $total) use ($job, &$last): void {
            if (microtime(true) - $last < 1.0 && $done < $total) {
                return;
            }
            $last = microtime(true);
            DB::connection('backup_progress')->table('backup_jobs')->where('id', $job->id)->update(['step' => $done, 'steps' => $total, 'updated_at' => now()]);
        };
    }

    /**
     * @return array<string, array{disk: string, key: string, written: bool, derivatives: bool}>
     */
    private function placements(BackupJob $job): array
    {
        $file = $this->workDirectory($job).'/placements.jsonl';
        $placements = [];
        foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry) && isset($entry['id'])) {
                $placements[(string) $entry['id']] = ['disk' => (string) $entry['disk'], 'key' => (string) $entry['key'], 'written' => (bool) $entry['written'], 'derivatives' => (bool) $entry['derivatives']];
            }
        }

        return $placements;
    }

    private function fail(BackupJob $job, Throwable $exception): void
    {
        $job->refresh();
        if ($job->status === BackupJob::STATUS_DONE) {
            return; // the swap committed; only the clean-up after it failed
        }
        if ($exception instanceof ArchiveBackupRefused) {
            $key = $job->kind === BackupJob::KIND_RESTORE ? 'operations.backup.errors.restore_refused' : 'operations.backup.errors.export_failed';
            $params = ['reason_key' => $exception->key, 'reason_params' => $exception->params];
        } else {
            Log::error('operations.backup.failed', ['job' => $job->id, 'kind' => $job->kind, 'exception' => $exception]);
            $key = $job->kind === BackupJob::KIND_RESTORE ? 'operations.backup.errors.restore_failed' : 'operations.backup.errors.export_failed_unexpected';
            $params = [];
        }
        $this->cleanUp($job);
        $job->forceFill(['status' => BackupJob::STATUS_FAILED, 'error_key' => $key, 'error_params' => $params, 'finished_at' => now()])->save();
    }

    private function cleanUp(BackupJob $job): void
    {
        if ($job->kind === BackupJob::KIND_RESTORE) {
            $this->restorer->removeWritten($this->placements($job));
            $this->discardStage((string) (($job->state ?? [])['token'] ?? ''));
        }
        File::deleteDirectory($this->workDirectory($job));
    }

    private function expireStale(): void
    {
        $limit = now()->subMinutes((int) config('operations.backup.stale_minutes'));
        foreach (BackupJob::query()->where('status', BackupJob::STATUS_RUNNING)->where('updated_at', '<', $limit)->get() as $job) {
            $this->cleanUp($job);
            $job->forceFill([
                'status' => BackupJob::STATUS_FAILED,
                'error_key' => $job->kind === BackupJob::KIND_RESTORE ? 'operations.backup.errors.interrupted_restore' : 'operations.backup.errors.interrupted_export',
                'error_params' => [],
                'finished_at' => now(),
            ])->save();
        }
    }

    /** @return array<string, mixed> */
    private function checkStage(string $token): array
    {
        $path = $this->stagedPath($token);
        $document = $this->restorer->read($path);
        $summary = $this->restorer->check($document, $path);
        file_put_contents($path.'.json', json_encode(['includes_files' => $summary['includes_files'], 'checked' => true, 'staged_at' => now()->toIso8601String()]));

        return $summary;
    }

    /** @return array<string, mixed> */
    private function stagedMeta(string $token): array
    {
        $meta = json_decode((string) @file_get_contents($this->stagedPath($token).'.json'), true);

        return is_array($meta) ? $meta : [];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function newStage(): array
    {
        $this->cleanupOldFiles();
        $token = bin2hex(random_bytes(16));

        return [$token, $this->stagedPath($token)];
    }

    /** Staged files and download results older than an hour are removed. */
    private function cleanupOldFiles(): void
    {
        $limit = time() - 3600;
        foreach ([...(glob($this->staging().'/.restore-*') ?: []), ...(glob($this->staging().'/export-*') ?: [])] as $file) {
            if (is_file($file) && filemtime($file) < $limit) {
                $token = preg_match('/\.restore-([0-9a-f]{32})/', $file, $match) === 1 ? $match[1] : null;
                if ($token !== null && BackupJob::query()->where('status', BackupJob::STATUS_RUNNING)->where('state->token', $token)->exists()) {
                    continue;
                }
                @unlink($file);
            }
        }
    }

    private function rotateAutomatic(): void
    {
        $keep = max(1, (int) config('operations.backup.auto.keep'));
        foreach (array_slice($this->automaticBackups(), $keep) as $old) {
            @unlink($this->autoDirectory().'/'.$old['name']);
        }
    }

    /** A temporary file older than the lock is the leftover of a crash. */
    private function cleanupAutoTemporaries(): void
    {
        foreach (glob($this->autoDirectory().'/.tmp-fotoarchief-auto-*') ?: [] as $file) {
            if (filemtime($file) < time() - 3600) {
                @unlink($file);
            }
        }
    }

    private function automaticTime(string $name): ?CarbonImmutable
    {
        if (preg_match(self::AUTO_PATTERN, $name, $m) !== 1) {
            return null;
        }

        $time = CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6], 'UTC');

        return $time instanceof CarbonImmutable ? $time : null;
    }

    private function lastAutomaticFailure(?CarbonImmutable $newest): ?BackupJob
    {
        $failure = BackupJob::query()->where('kind', BackupJob::KIND_AUTO)->where('status', BackupJob::STATUS_FAILED)->orderByDesc('finished_at')->first();
        if ($failure === null || $failure->finished_at === null) {
            return null;
        }

        return $newest === null || $failure->finished_at->greaterThan($newest) ? $failure : null;
    }

    private function nextAutomaticAt(?CarbonImmutable $newest, ?BackupJob $failure): ?CarbonImmutable
    {
        $next = $newest?->addHours(max(1, (int) config('operations.backup.auto.interval_hours')));
        $failedAt = $failure instanceof BackupJob ? $failure->finished_at : null;
        if ($failedAt !== null) {
            $retry = $failedAt->addMinutes((int) config('operations.backup.auto.retry_minutes'));
            $next = $next === null || $retry->greaterThan($next) ? $retry : $next;
        }

        return $next;
    }

    private function hasAdministrator(): bool
    {
        $role = Role::query()->where('key', 'administrator')->first();

        return $role !== null && User::query()->where('is_active', true)->whereHas('roles', fn ($query) => $query->whereKey($role->id))->exists();
    }

    private function staging(): string
    {
        $path = (string) config('operations.backup.staging_path');
        File::ensureDirectoryExists($path, 0750);

        return $path;
    }

    private function workDirectory(BackupJob $job): string
    {
        $path = $this->staging().'/work-'.$job->id;
        File::ensureDirectoryExists($path, 0750);

        return $path;
    }

    private function autoDirectory(): string
    {
        return (string) config('operations.backup.auto.path');
    }

    private function megabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 0, ',', '.');
    }
}
