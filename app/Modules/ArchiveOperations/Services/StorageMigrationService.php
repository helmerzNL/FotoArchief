<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Models\User;
use App\Modules\ArchiveOperations\Models\StorageMigration;
use App\Modules\ArchiveOperations\Models\StorageRelocation;
use App\Modules\ArchiveOperations\Models\StorageTombstone;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class StorageMigrationService
{
    public function __construct(private readonly S3ProtectionPolicy $s3Protection) {}

    /**
     * @return array<string, array{driver: string, file_count: int}>
     */
    public function getAvailableDisks(): array
    {
        $configuredDisks = array_keys(config('filesystems.disks', []));
        $result = [];

        foreach ($configuredDisks as $diskName) {
            $driver = config("filesystems.disks.{$diskName}.driver", 'unknown');
            $fileCount = $this->sourceFiles((string) $diskName)->count();

            $result[(string) $diskName] = [
                'driver' => is_string($driver) ? $driver : 'unknown',
                'file_count' => (int) $fileCount,
            ];
        }

        return $result;
    }

    /**
     * Registers the migration without moving a single byte.
     *
     * Copying is bounded work for the ingest queue; an HTTP request only records the
     * intent so the operator immediately has a run to watch.
     *
     * @param  array{
     *     ready: true,
     *     source_disk: string,
     *     target_disk: string,
     *     file_count: int,
     *     required_bytes: int,
     *     required_with_headroom: int,
     *     available_bytes: int|null,
     *     capacity: 'sufficient'|'provider-managed',
     *     versioning: 'not-applicable'|'verified',
     *     checked_at: string
     * }|null  $preflight
     */
    public function prepareMigration(string $sourceDisk, string $targetDisk, User $user, ?array $preflight = null): StorageMigration
    {
        if ($sourceDisk === $targetDisk) {
            throw new RuntimeException(__('operations.generated.t_ec41088f129fa460'));
        }

        return StorageMigration::create([
            'id' => (string) Str::ulid(),
            'source_disk' => $sourceDisk,
            'target_disk' => $targetDisk,
            'status' => 'verifying',
            'total_files' => $this->sourceFiles($sourceDisk)->count(),
            'copied_files' => 0,
            'verified_files' => 0,
            'failed_files' => 0,
            'required_bytes' => (int) ($preflight['required_bytes'] ?? 0),
            'available_bytes' => $preflight['available_bytes'] ?? null,
            'preflight_report' => $preflight,
            'initiated_by_user_id' => $user->id,
        ]);
    }

    /**
     * @return array{
     *     ready: true,
     *     source_disk: string,
     *     target_disk: string,
     *     file_count: int,
     *     required_bytes: int,
     *     required_with_headroom: int,
     *     available_bytes: int|null,
     *     capacity: 'sufficient'|'provider-managed',
     *     versioning: 'not-applicable'|'verified',
     *     checked_at: string
     * }
     */
    public function preflightMigration(string $sourceDisk, string $targetDisk): array
    {
        if ($sourceDisk === $targetDisk) {
            throw new RuntimeException(__('operations.generated.t_ec41088f129fa460'));
        }
        $sourceConfig = config("filesystems.disks.{$sourceDisk}");
        $targetConfig = config("filesystems.disks.{$targetDisk}");
        if (! is_array($sourceConfig) || ! is_array($targetConfig)) {
            throw new RuntimeException('Source and target storage disks must be configured.');
        }
        $files = $this->sourceFiles($sourceDisk);
        $fileCount = (int) $files->count();
        $requiredBytes = (int) $files->sum('byte_size');
        $requiredWithHeadroom = (int) ceil($requiredBytes * 1.1);
        $availableBytes = null;
        $capacity = 'provider-managed';
        $targetDriver = $targetConfig['driver'] ?? null;
        $versioning = 'not-applicable';

        if ($targetDriver === 'local') {
            $root = $targetConfig['root'] ?? null;
            $available = is_string($root) ? disk_free_space($root) : false;
            if ($available === false) {
                throw new RuntimeException('Target storage capacity could not be measured.');
            }
            $availableBytes = (int) $available;
            if ($availableBytes < $requiredWithHeadroom) {
                throw new RuntimeException('Target storage has insufficient free capacity including ten percent headroom.');
            }
            $capacity = 'sufficient';
        } elseif ($targetDriver === 's3') {
            $this->s3Protection->verifyVersioning($targetDisk);
            $versioning = 'verified';
        }

        $probeKey = 'migration-preflight/'.(string) Str::ulid();
        $target = Storage::disk($targetDisk);
        if (! $target->put($probeKey, 'fotoarchief-preflight', ['visibility' => 'private'])
            || $target->get($probeKey) !== 'fotoarchief-preflight'
            || ! $target->delete($probeKey)) {
            throw new RuntimeException('Target storage write, read, and delete probe failed.');
        }

        return [
            'ready' => true,
            'source_disk' => $sourceDisk,
            'target_disk' => $targetDisk,
            'file_count' => $fileCount,
            'required_bytes' => $requiredBytes,
            'required_with_headroom' => $requiredWithHeadroom,
            'available_bytes' => $availableBytes,
            'capacity' => $capacity,
            'versioning' => $versioning,
            'checked_at' => now()->toAtomString(),
        ];
    }

    /**
     * A chunk is bounded by item count and by bytes. Counting items alone is not
     * enough: twenty-five 100 MB originals on a slow target disk do not finish
     * inside the 120s job timeout, and a job killed halfway leaves the operator
     * with no progress at all. Once this many bytes have been copied the chunk
     * stops and the next job continues from the cursor.
     */
    public const int CHUNK_BYTE_BUDGET = 268435456;

    /**
     * Copies and verifies at most $limit files after $afterFileId, stopping early
     * once the chunk byte budget is reached.
     *
     * @return array{processed: int, failed: int, finished: bool, last_id: string|null}
     */
    public function relocateChunk(StorageMigration $migration, ?string $afterFileId, int $limit): array
    {
        $files = $this->sourceFiles($migration->source_disk)
            ->when($afterFileId !== null, fn ($query) => $query->where('id', '>', $afterFileId))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $processed = 0;
        $failed = 0;
        $lastId = $afterFileId;
        $bytes = 0;
        $budgetReached = false;

        foreach ($files as $file) {
            $lastId = $file->id;

            // Retrying a chunk must not copy or count a file twice: the previous
            // attempt may have been killed after verifying some of them.
            if ($this->alreadyRelocated($migration, $file)) {
                $processed++;

                continue;
            }

            if ($this->relocateFile($migration, $file)) {
                $processed++;
            } else {
                $failed++;
            }

            $bytes += (int) $file->byte_size;
            if ($bytes >= self::CHUNK_BYTE_BUDGET) {
                $budgetReached = true;

                break;
            }
        }

        $this->reconcileProgress($migration);

        return [
            'processed' => $processed,
            'failed' => $failed,
            'finished' => ! $budgetReached && $files->count() < $limit,
            'last_id' => $lastId,
        ];
    }

    /**
     * True when this file was already copied and checksum-verified for this
     * migration, so the work is done and must not be repeated or recounted.
     */
    private function alreadyRelocated(StorageMigration $migration, AssetFile $file): bool
    {
        return StorageRelocation::query()
            ->where('storage_migration_id', $migration->id)
            ->where('asset_file_id', $file->id)
            ->where('is_verified', true)
            ->exists();
    }

    /** @return Builder<AssetFile> */
    private function sourceFiles(string $disk): Builder
    {
        return AssetFile::query()->where(function (Builder $query) use ($disk): void {
            $query->where('storage_disk', $disk);
            if ($disk === 'local') {
                $query->orWhereNull('storage_disk');
            }
        });
    }

    /**
     * Copies one original plus its derivatives and verifies the target checksum.
     * Returns false when the file could not be relocated; the reason is persisted
     * on the relocation row so the operator sees a per-file error, not a dead run.
     */
    public function relocateFile(StorageMigration $migration, AssetFile $file): bool
    {
        $sourceStorage = Storage::disk($migration->source_disk);
        $targetStorage = Storage::disk($migration->target_disk);

        try {
            if (! $sourceStorage->exists($file->storage_key)) {
                throw new RuntimeException("Bronbestand [{$file->storage_key}] ontbreekt op schijf [{$migration->source_disk}].");
            }

            $stream = $sourceStorage->readStream($file->storage_key);
            if (! is_resource($stream)) {
                throw new RuntimeException("Kan bronbestand [{$file->storage_key}] niet lezen.");
            }

            try {
                $written = $targetStorage->put($file->storage_key, $stream, ['visibility' => 'private']);
            } finally {
                fclose($stream);
            }

            if (! $written) {
                throw new RuntimeException("Schrijven naar doelbestand [{$file->storage_key}] mislukt.");
            }

            $derivatives = (array) ($file->derivatives ?? []);
            foreach ($derivatives as $key) {
                if (! is_string($key) || ! $sourceStorage->exists($key)) {
                    throw new RuntimeException(__('operations.storage.incomplete_derivative'));
                }
                $derivStream = $sourceStorage->readStream($key);
                if (! is_resource($derivStream)) {
                    throw new RuntimeException(__('operations.storage.incomplete_derivative'));
                }
                try {
                    if (! $targetStorage->put($key, $derivStream, ['visibility' => 'private', 'ContentType' => 'image/jpeg'])) {
                        throw new RuntimeException(__('operations.storage.incomplete_derivative'));
                    }
                } finally {
                    fclose($derivStream);
                }
            }

            $checksums = $this->verifyTarget($file, $sourceStorage, $targetStorage);

            $this->recordRelocation($migration, $file, $file->sha256, true, derivatives: $checksums);

            return true;
        } catch (\Throwable $exception) {
            $this->recordRelocation($migration, $file, $file->sha256, false, Str::limit($exception->getMessage(), 950));

            return false;
        }
    }

    /** @param array<string, string>|null $derivatives */
    private function recordRelocation(StorageMigration $migration, AssetFile $file, string $checksum, bool $verified, ?string $error = null, ?array $derivatives = null): void
    {
        DB::transaction(function () use ($migration, $file, $checksum, $verified, $error, $derivatives): void {
            StorageMigration::query()->whereKey($migration->id)->lockForUpdate()->firstOrFail();
            StorageRelocation::query()->updateOrCreate([
                'storage_migration_id' => $migration->id,
                'asset_file_id' => $file->id,
            ], [
                'source_disk' => $migration->source_disk,
                'target_disk' => $migration->target_disk,
                'source_key' => $file->storage_key,
                'target_key' => $file->storage_key,
                'sha256' => $checksum,
                'is_verified' => $verified,
                'verified_derivatives' => $derivatives,
                'cutover_completed_at' => null,
                'error_message' => $error,
            ]);
            $this->reconcileProgress($migration);
        });
    }

    private function reconcileProgress(StorageMigration $migration): void
    {
        DB::transaction(function () use ($migration): void {
            StorageMigration::query()->whereKey($migration->id)->lockForUpdate()->firstOrFail();
            $successful = $migration->relocations()->where('is_verified', true)->count();
            $migration->update([
                'copied_files' => $successful,
                'verified_files' => $successful,
                'failed_files' => $migration->relocations()->where('is_verified', false)->count(),
            ]);
        });
    }

    /**
     * Seals the migration once every file has been attempted. Only a migration with
     * zero failures becomes 'verified' and therefore eligible for cutover.
     */
    public function finalizeMigration(StorageMigration $migration): StorageMigration
    {
        $this->reconcileProgress($migration);
        $migration->refresh();

        $migration->update([
            'status' => ($migration->failed_files === 0 && $migration->verified_files === $migration->total_files)
                ? 'verified'
                : 'failed_verification',
        ]);

        return $migration->refresh();
    }

    /**
     * Synchronous whole-archive relocation, retained for direct service-level use and
     * for small archives; the HTTP path always goes through the queued run instead.
     */
    public function startMigration(string $sourceDisk, string $targetDisk, User $user): StorageMigration
    {
        $migration = $this->prepareMigration($sourceDisk, $targetDisk, $user);

        $cursor = null;
        do {
            $chunk = $this->relocateChunk($migration, $cursor, 100);
            $cursor = $chunk['last_id'];
        } while (! $chunk['finished']);

        return $this->finalizeMigration($migration);
    }

    public function cutover(StorageMigration $migration, User $user): void
    {
        if ($migration->status !== 'verified') {
            throw new RuntimeException(__('operations.generated.t_7ab5137d22fa83a3'));
        }

        DB::transaction(function () use ($migration): void {
            $migration = StorageMigration::query()->whereKey($migration->id)->lockForUpdate()->firstOrFail();
            if ($migration->status !== 'verified'
                || $migration->relocations()->where('is_verified', true)->count() !== $migration->total_files
                || $this->sourceFiles($migration->source_disk)->count() !== $migration->total_files) {
                throw new RuntimeException(__('operations.storage.source_changed'));
            }
            foreach ($migration->relocations()->where('is_verified', true)->cursor() as $relocation) {
                $file = AssetFile::query()->whereKey($relocation->asset_file_id)->lockForUpdate()->firstOrFail();
                if (($file->storage_disk ?? 'local') !== $migration->source_disk) {
                    throw new RuntimeException(__('operations.storage.source_changed'));
                }
                if ($file->storage_key !== $relocation->target_key || $file->sha256 !== $relocation->sha256) {
                    throw new RuntimeException(__('operations.storage.source_changed'));
                }
                $checksums = $this->verifyTarget($file, Storage::disk($migration->source_disk), Storage::disk($migration->target_disk), $relocation->verified_derivatives);
                $relocation->update(['verified_derivatives' => $checksums]);
                $file->update(['storage_disk' => $migration->target_disk]);
            }
            $migration->relocations()->where('is_verified', true)->update([
                'cutover_completed_at' => now(),
            ]);

            $migration->update([
                'status' => 'cutover_completed',
                'cutover_at' => now(),
            ]);
        });
    }

    public function cleanupSourceFiles(StorageMigration $migration, User $user): int
    {
        $migration->refresh();
        if ($migration->status !== 'cutover_completed') {
            throw new RuntimeException(__('operations.generated.t_5f360cd8359c9635'));
        }

        $sourceStorage = Storage::disk($migration->source_disk);
        $targetStorage = Storage::disk($migration->target_disk);
        $sourceConfig = config("filesystems.disks.{$migration->source_disk}");
        $versionedSource = is_array($sourceConfig) && ($sourceConfig['driver'] ?? null) === 's3';
        if ($versionedSource) {
            $this->s3Protection->verifyVersioning($migration->source_disk);
        }
        $relocations = $migration->relocations()->whereNotNull('cutover_completed_at')->get();
        if ($relocations->count() !== $migration->total_files) {
            throw new RuntimeException(__('operations.storage.source_changed'));
        }
        $deletedCount = 0;

        foreach ($relocations as $relocation) {
            $file = $this->cleanupFile($migration, $relocation);
            $checksums = $this->verifyTarget($file, $sourceStorage, $targetStorage, $relocation->verified_derivatives);
            $relocation->update(['verified_derivatives' => $checksums]);
        }
        foreach ($relocations as $relocation) {
            $file = $this->cleanupFile($migration, $relocation);
            $this->verifyTarget($file, $sourceStorage, $targetStorage, $relocation->verified_derivatives);
            foreach ([$relocation->source_key, ...array_keys($relocation->verified_derivatives ?? [])] as $key) {
                $tombstone = null;
                if ($versionedSource) {
                    $tombstone = StorageTombstone::query()->updateOrCreate([
                        'storage_migration_id' => $migration->id,
                        'object_key' => $key,
                    ], [
                        'storage_relocation_id' => $relocation->id,
                        'disk' => $migration->source_disk,
                        'sha256' => $key === $relocation->source_key
                            ? $relocation->sha256
                            : ($relocation->verified_derivatives[$key] ?? ''),
                        'status' => 'pending',
                        'retained_until' => now()->addDays(max(1, (int) config('recovery.storage_protection.tombstone_retention_days', 30))),
                        'deleted_at' => null,
                    ]);
                }
                if ($sourceStorage->exists($key) && ! $sourceStorage->delete($key)) {
                    throw new RuntimeException(__('operations.storage.cleanup_failed'));
                }
                $tombstone?->update(['status' => 'deleted', 'deleted_at' => now()]);
            }
            $deletedCount++;
        }

        $migration->update([
            'status' => 'completed',
            'source_cleaned_at' => now(),
        ]);

        return $deletedCount;
    }

    private function cleanupFile(StorageMigration $migration, StorageRelocation $relocation): AssetFile
    {
        $file = AssetFile::query()->find($relocation->asset_file_id);
        if ($file === null || ! $relocation->is_verified
            || $relocation->source_disk !== $migration->source_disk
            || $relocation->target_disk !== $migration->target_disk
            || ($file->storage_disk ?? 'local') !== $migration->target_disk
            || $file->storage_key !== $relocation->target_key
            || $file->sha256 !== $relocation->sha256) {
            throw new RuntimeException(__('operations.storage.source_changed'));
        }

        return $file;
    }

    /**
     * @param  array<string, string>|null  $expectedDerivatives
     * @return array<string, string>
     */
    private function verifyTarget(AssetFile $file, Filesystem $source, Filesystem $target, ?array $expectedDerivatives = null): array
    {
        if (! $target->exists($file->storage_key) || $this->calculateSha256($target, $file->storage_key) !== $file->sha256) {
            throw new RuntimeException(__('operations.storage.target_invalid'));
        }
        $checksums = [];
        foreach ((array) ($file->derivatives ?? []) as $key) {
            if (! is_string($key) || ! $target->exists($key)
                || ($expectedDerivatives === null && ! $source->exists($key))) {
                throw new RuntimeException(__('operations.storage.incomplete_derivative'));
            }
            $expected = $expectedDerivatives === null ? $this->calculateSha256($source, $key) : ($expectedDerivatives[$key] ?? null);
            if ($expected === null || $expected !== $this->calculateSha256($target, $key)) {
                throw new RuntimeException(__('operations.storage.incomplete_derivative'));
            }
            $checksums[$key] = $expected;
        }
        if ($expectedDerivatives !== null && count($checksums) !== count($expectedDerivatives)) {
            throw new RuntimeException(__('operations.storage.incomplete_derivative'));
        }

        return $checksums;
    }

    private function calculateSha256(Filesystem $storage, string $path): string
    {
        $stream = $storage->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException("Kan bestand niet lezen voor SHA-256 berekening: {$path}");
        }

        $context = hash_init('sha256');
        try {
            while (! feof($stream)) {
                $buffer = fread($stream, 1024 * 1024);
                if ($buffer === false || ($buffer === '' && ! feof($stream))) {
                    throw new RuntimeException("Kan bestand niet lezen voor SHA-256 berekening: {$path}");
                }
                hash_update($context, $buffer);
            }
        } finally {
            fclose($stream);
        }

        return hash_final($context);
    }
}
