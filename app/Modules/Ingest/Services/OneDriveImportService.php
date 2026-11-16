<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Services;

use App\Console\Commands\ImportDirectoryCommand;
use App\Models\User;
use App\Modules\ArchiveOperations\Exceptions\PermanentOperationFailure;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\Ingest\Exceptions\OneDriveDeltaExpired;
use App\Modules\Ingest\Exceptions\OneDriveThrottled;
use App\Modules\Ingest\Models\OneDriveConnection;
use App\Modules\Ingest\Models\OneDriveImportedItem;
use App\Modules\Ingest\Support\IngestBacklog;
use App\Modules\Ingest\Support\OriginalPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * One bounded chunk of a OneDrive import or survey (ADR-0022).
 *
 * Every file takes the same road as ingest:import-directory: the same extension list
 * and size limit, then QuarantineUploadService (quarantine, scan, checksum,
 * duplicates, previews). An import keeps its position on the connection, so a stopped
 * or failed run is continued by the next one, and the delta link it ends with makes
 * the next import read only what changed. A survey only counts and changes nothing.
 */
class OneDriveImportService
{
    public const MODE_IMPORT = 'import';

    public const MODE_SURVEY = 'survey';

    private const PROBLEM_LIMIT = 20;

    public function __construct(
        private readonly OneDriveClient $client,
        private readonly QuarantineUploadService $uploads,
    ) {}

    /**
     * @param  bool  $lastChunk  the run is at its chunk limit and must hand over to a new run
     * @return array{processed: int, failed: int, finished: bool, progressed: bool, delay_seconds?: int, result?: array<string, mixed>}
     */
    public function runChunk(OperationRun $run, bool $lastChunk): array
    {
        $connection = OneDriveConnection::current();
        if (! $connection->isConnected() || ! $connection->hasFolder()) {
            throw new PermanentOperationFailure(__('operations.onedrive.errors.not_ready'));
        }
        $payload = $run->payload ?? [];
        $survey = ($payload['mode'] ?? self::MODE_IMPORT) === self::MODE_SURVEY;
        $stats = is_array($payload['stats'] ?? null) ? $payload['stats'] : [];

        if (! $survey && IngestBacklog::pending() >= (int) config('ingest.onedrive.max_pending', 200)) {
            return $lastChunk
                ? $this->handOver($run, $connection, $survey, true, 0, 0, $stats)
                : $this->wait((int) config('ingest.onedrive.backlog_wait_seconds', 60));
        }

        [$page, $offset] = $survey
            ? [is_string($payload['page'] ?? null) ? $payload['page'] : null, (int) ($payload['offset'] ?? 0)]
            : [$connection->resume_link ?? $connection->delta_link, $connection->resume_link !== null ? $connection->resume_offset : 0];
        $page ??= $this->start($connection);

        try {
            $response = $this->client->get($connection, $page);
        } catch (OneDriveThrottled $exception) {
            return $lastChunk
                ? $this->handOver($run, $connection, $survey, true, 0, 0, $stats)
                : $this->wait($exception->retryAfterSeconds);
        } catch (OneDriveDeltaExpired) {
            // Read the folder again from the start; imported items are skipped by ID.
            $this->savePosition($run, $connection, $survey, null, 0, $stats, deltaLink: null);

            return ['processed' => 0, 'failed' => 0, 'finished' => false, 'progressed' => true];
        }

        $items = array_values(array_filter((array) ($response['value'] ?? []), 'is_array'));
        $user = $run->requestedBy;
        $started = microtime(true);
        $processed = $failed = $downloads = 0;
        for ($index = $offset; $index < count($items); $index++) {
            if (! $survey && ($downloads >= (int) config('ingest.onedrive.files_per_chunk', 25)
                || microtime(true) - $started >= (int) config('ingest.onedrive.start_downloads_until_seconds', 60))) {
                break;
            }
            try {
                $outcome = $this->handle($connection, $items[$index], $survey, $user, $started, $stats);
            } catch (OneDriveThrottled $exception) {
                $this->savePosition($run, $connection, $survey, $page, $index, $stats);

                return $lastChunk
                    ? $this->handOver($run, $connection, $survey, true, $processed, $failed, $stats)
                    : $this->wait($exception->retryAfterSeconds, $processed, $failed);
            }
            if ($outcome === 'imported') {
                $processed++;
                $downloads++;
            } elseif ($outcome === 'failed') {
                $failed++;
                $downloads++;
            }
            $offset = $index + 1;
        }

        if ($offset < count($items)) {
            $this->savePosition($run, $connection, $survey, $page, $offset, $stats);

            return $this->handOver($run, $connection, $survey, $lastChunk, $processed, $failed, $stats);
        }
        $next = is_string($response['@odata.nextLink'] ?? null) ? $response['@odata.nextLink'] : null;
        if ($next !== null) {
            $this->savePosition($run, $connection, $survey, $next, 0, $stats);

            return $this->handOver($run, $connection, $survey, $lastChunk, $processed, $failed, $stats);
        }

        $delta = is_string($response['@odata.deltaLink'] ?? null) ? $response['@odata.deltaLink'] : null;
        $this->savePosition($run, $connection, $survey, null, 0, $stats, deltaLink: $survey ? false : $delta);

        return [
            'processed' => $processed, 'failed' => $failed, 'finished' => true, 'progressed' => true,
            'result' => ['mode' => $survey ? self::MODE_SURVEY : self::MODE_IMPORT, 'complete' => true, 'stats' => $stats],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $stats
     * @return 'imported'|'failed'|'skipped'
     */
    private function handle(OneDriveConnection $connection, array $item, bool $survey, ?User $user, float $started, array &$stats): string
    {
        $itemId = is_string($item['id'] ?? null) ? $item['id'] : null;
        if ($itemId === null || isset($item['deleted'])) {
            $this->count($stats, 'deleted');

            return 'skipped';
        }
        if (isset($item['folder']) || isset($item['root'])) {
            // A renamed or moved folder shows up again; forget its cached path.
            DB::table('onedrive_folders')->where('item_id', $itemId)->delete();
            $this->count($stats, 'folders');

            return 'skipped';
        }
        if (! isset($item['file']) || ! is_string($item['name'] ?? null)) {
            $this->count($stats, 'ignored');

            return 'skipped';
        }
        $name = $item['name'];
        $size = (int) ($item['size'] ?? 0);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $stats['types'][$extension]['files'] = (int) ($stats['types'][$extension]['files'] ?? 0) + 1;
        $stats['types'][$extension]['bytes'] = (int) ($stats['types'][$extension]['bytes'] ?? 0) + $size;
        if (! in_array($extension, ImportDirectoryCommand::SUPPORTED, true)) {
            $this->count($stats, in_array($extension, ImportDirectoryCommand::NOT_YET_SUPPORTED, true) ? 'unsupported' : 'ignored');

            return 'skipped';
        }
        if ($size <= 0 || $size > (int) config('ingest.max_upload_bytes')) {
            $this->count($stats, 'too_large');

            return 'skipped';
        }
        if (OneDriveImportedItem::query()->where('item_id', $itemId)->exists()) {
            $this->count($stats, 'existing');

            return 'skipped';
        }
        if ($survey) {
            $this->count($stats, 'new');
            $stats['new_bytes'] = (int) ($stats['new_bytes'] ?? 0) + $size;

            return 'skipped';
        }

        try {
            $relative = OriginalPath::normalize($this->relativePath($connection, $item, $name));
        } catch (InvalidArgumentException) {
            $this->problem($stats, $name.' ('.__('operations.onedrive.problems.path').')');
            $this->count($stats, 'rejected');

            return 'skipped';
        }

        if (! $user instanceof User) {
            throw new PermanentOperationFailure(__('operations.onedrive.errors.no_user'));
        }
        $temporary = tempnam(sys_get_temp_dir(), 'fotoarchief-onedrive-');
        if ($temporary === false) {
            throw new RuntimeException(__('uploads.storage_error'));
        }
        try {
            $remaining = (int) config('ingest.onedrive.chunk_seconds', 100) - (int) (microtime(true) - $started);
            $this->client->download($connection, $itemId, $temporary, (int) config('ingest.max_upload_bytes'), $remaining);
            $upload = $this->uploads->quarantineNewAsset([
                'accession_number' => 'FA-'.str()->ulid(),
                'title' => mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 255),
                'created_by_user_id' => $user->id,
            ], new UploadedFile($temporary, $name, test: true), $user->id, $relative, allowExtendedTypes: true);
            $this->remember($itemId, $item, $upload->id, 'queued', $relative);
            $this->count($stats, 'imported');
            $stats['imported_bytes'] = (int) ($stats['imported_bytes'] ?? 0) + $size;

            return 'imported';
        } catch (OneDriveThrottled $exception) {
            throw $exception;
        } catch (ValidationException) {
            // The content is not what the name says; retrying cannot help.
            $this->remember($itemId, $item, null, 'rejected', $relative);
            $this->problem($stats, $relative.' ('.__('operations.onedrive.problems.rejected').')');
            $this->count($stats, 'rejected');

            return 'skipped';
        } catch (PermanentOperationFailure $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Not remembered, so the next import tries this file again.
            $this->problem($stats, $relative.' ('.mb_substr($exception->getMessage(), 0, 120).')');
            $this->count($stats, 'failed');

            return 'failed';
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * "<chosen folder>/<subfolders>/<file name>"; the chosen folder's own name leads, or
     * "OneDrive" when the whole drive is the source.
     *
     * @param  array<string, mixed>  $item
     */
    private function relativePath(OneDriveConnection $connection, array $item, string $name): string
    {
        $parentPath = is_string($item['parentReference']['path'] ?? null)
            ? OneDriveClient::displayPath($item['parentReference']['path'])
            : $this->folderPath($connection, is_string($item['parentReference']['id'] ?? null) ? $item['parentReference']['id'] : null);
        $root = rtrim((string) $connection->folder_path, '/');
        if ($root !== '' && $parentPath !== $root && ! str_starts_with($parentPath, $root.'/')) {
            throw new InvalidArgumentException('outside the chosen folder');
        }
        $below = trim(substr($parentPath, strlen($root)), '/');
        $lead = (string) ($connection->folder_name ?: 'OneDrive');

        return implode('/', array_filter([$lead, $below, $name], fn (string $part): bool => $part !== ''));
    }

    private function folderPath(OneDriveConnection $connection, ?string $folderId): string
    {
        if ($folderId === null) {
            throw new InvalidArgumentException('no parent');
        }
        $cached = DB::table('onedrive_folders')->where('item_id', $folderId)->value('path');
        if (is_string($cached)) {
            return $cached;
        }
        $folder = $this->client->get($connection, '/me/drive/items/'.rawurlencode($folderId), ['$select' => 'id,name,parentReference,root']);
        $path = isset($folder['root'])
            ? '/'
            : OneDriveClient::displayPath((string) ($folder['parentReference']['path'] ?? '/drive/root:').'/'.(string) ($folder['name'] ?? ''));
        DB::table('onedrive_folders')->upsert([['item_id' => $folderId, 'path' => mb_substr($path, 0, 1000), 'created_at' => now(), 'updated_at' => now()]], ['item_id'], ['path', 'updated_at']);

        return $path;
    }

    /** @param array<string, mixed> $item */
    private function remember(string $itemId, array $item, ?string $uploadId, string $outcome, string $relative): void
    {
        OneDriveImportedItem::query()->updateOrCreate(['item_id' => $itemId], [
            'ctag' => is_string($item['cTag'] ?? null) ? mb_substr($item['cTag'], 0, 200) : null,
            'quarantine_upload_id' => $uploadId,
            'outcome' => $outcome,
            'original_path' => mb_substr($relative, 0, 1000),
        ]);
    }

    private function start(OneDriveConnection $connection): string
    {
        return '/me/drive/items/'.rawurlencode((string) $connection->folder_item_id).'/delta';
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  string|false|null  $deltaLink  false = leave the stored delta link alone
     */
    private function savePosition(OperationRun $run, OneDriveConnection $connection, bool $survey, ?string $page, int $offset, array $stats, string|false|null $deltaLink = false): void
    {
        $payload = $run->payload ?? [];
        $payload['stats'] = $stats;
        if ($survey) {
            $payload['page'] = $page;
            $payload['offset'] = $offset;
        } else {
            $changes = ['resume_link' => $page, 'resume_offset' => $page === null ? 0 : $offset];
            if ($deltaLink !== false) {
                $changes['delta_link'] = $deltaLink;
            }
            $connection->forceFill($changes)->save();
        }
        $run->forceFill(['payload' => $payload])->save();
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array{processed: int, failed: int, finished: bool, progressed: bool, result?: array<string, mixed>}
     */
    private function handOver(OperationRun $run, OneDriveConnection $connection, bool $survey, bool $lastChunk, int $processed, int $failed, array $stats): array
    {
        if (! $lastChunk) {
            return ['processed' => $processed, 'failed' => $failed, 'finished' => false, 'progressed' => true];
        }

        // The run is at its chunk limit. An import keeps its position on the connection,
        // so it ends here as "not complete" and the next import continues; a survey
        // reports what it counted so far.
        return [
            'processed' => $processed, 'failed' => $failed, 'finished' => true, 'progressed' => true,
            'result' => ['mode' => $survey ? self::MODE_SURVEY : self::MODE_IMPORT, 'complete' => false, 'stats' => $stats],
        ];
    }

    /** @return array{processed: int, failed: int, finished: bool, progressed: bool, delay_seconds: int} */
    private function wait(int $seconds, int $processed = 0, int $failed = 0): array
    {
        return ['processed' => $processed, 'failed' => $failed, 'finished' => false, 'progressed' => true, 'delay_seconds' => max(1, $seconds)];
    }

    /** @param array<string, mixed> $stats */
    private function count(array &$stats, string $key): void
    {
        $stats[$key] = (int) ($stats[$key] ?? 0) + 1;
    }

    /** @param array<string, mixed> $stats */
    private function problem(array &$stats, string $problem): void
    {
        $problems = is_array($stats['problems'] ?? null) ? $stats['problems'] : [];
        if (count($problems) < self::PROBLEM_LIMIT) {
            $problems[] = $problem;
        }
        $stats['problems'] = $problems;
    }
}
