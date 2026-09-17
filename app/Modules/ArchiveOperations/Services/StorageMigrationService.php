<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Models\User;
use App\Modules\ArchiveOperations\Models\StorageMigration;
use App\Modules\ArchiveOperations\Models\StorageRelocation;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class StorageMigrationService
{
    /**
     * @return array<string, array{driver: string, file_count: int}>
     */
    public function getAvailableDisks(): array
    {
        $configuredDisks = array_keys(config('filesystems.disks', []));
        $result = [];

        foreach ($configuredDisks as $diskName) {
            $driver = config("filesystems.disks.{$diskName}.driver", 'unknown');
            $fileCount = AssetFile::query()->count();

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
     */
    public function prepareMigration(string $sourceDisk, string $targetDisk, User $user): StorageMigration
    {
        if ($sourceDisk === $targetDisk) {
            throw new RuntimeException('Bron- en doelschijf mogen niet identiek zijn.');
        }

        return StorageMigration::create([
            'id' => (string) Str::ulid(),
            'source_disk' => $sourceDisk,
            'target_disk' => $targetDisk,
            'status' => 'verifying',
            'total_files' => AssetFile::query()->count(),
            'copied_files' => 0,
            'verified_files' => 0,
            'failed_files' => 0,
            'initiated_by_user_id' => $user->id,
        ]);
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
        $files = AssetFile::query()
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

            $written = $targetStorage->put($file->storage_key, $stream, ['visibility' => 'private']);
            fclose($stream);

            if (! $written) {
                throw new RuntimeException("Schrijven naar doelbestand [{$file->storage_key}] mislukt.");
            }

            $derivatives = (array) ($file->derivatives ?? []);
            foreach ($derivatives as $key) {
                if (is_string($key) && $sourceStorage->exists($key)) {
                    $derivStream = $sourceStorage->readStream($key);
                    if (is_resource($derivStream)) {
                        $targetStorage->put($key, $derivStream, ['visibility' => 'private', 'ContentType' => 'image/jpeg']);
                        fclose($derivStream);
                    }
                }
            }

            $targetSha256 = $this->calculateSha256($targetStorage, $file->storage_key);
            if ($targetSha256 !== $file->sha256) {
                throw new RuntimeException("Checksum mismatch op doel: verwacht {$file->sha256}, kreeg {$targetSha256}");
            }

            StorageRelocation::updateOrCreate([
                'storage_migration_id' => $migration->id,
                'asset_file_id' => $file->id,
            ], [
                'source_disk' => $migration->source_disk,
                'target_disk' => $migration->target_disk,
                'source_key' => $file->storage_key,
                'target_key' => $file->storage_key,
                'sha256' => $targetSha256,
                'is_verified' => true,
                'cutover_completed_at' => null,
                'error_message' => null,
            ]);

            $migration->increment('copied_files');
            $migration->increment('verified_files');

            return true;
        } catch (\Throwable $exception) {
            StorageRelocation::updateOrCreate([
                'storage_migration_id' => $migration->id,
                'asset_file_id' => $file->id,
            ], [
                'source_disk' => $migration->source_disk,
                'target_disk' => $migration->target_disk,
                'source_key' => $file->storage_key,
                'target_key' => $file->storage_key,
                'sha256' => $file->sha256,
                'is_verified' => false,
                'error_message' => Str::limit($exception->getMessage(), 950),
            ]);

            $migration->increment('failed_files');

            return false;
        }
    }

    /**
     * Seals the migration once every file has been attempted. Only a migration with
     * zero failures becomes 'verified' and therefore eligible for cutover.
     */
    public function finalizeMigration(StorageMigration $migration): StorageMigration
    {
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
            throw new RuntimeException('Alleen volledig geverifieerde migraties kunnen omgezet worden (cutover).');
        }

        DB::transaction(function () use ($migration): void {
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
        if ($migration->status !== 'cutover_completed') {
            throw new RuntimeException('Bronbestanden mogen pas worden opgeruimd na succesvolle cutover.');
        }

        $sourceStorage = Storage::disk($migration->source_disk);
        $targetStorage = Storage::disk($migration->target_disk);
        $relocations = $migration->relocations()->whereNotNull('cutover_completed_at')->get();
        $deletedCount = 0;

        foreach ($relocations as $relocation) {
            // Verify target file actually exists before deleting source
            if ($targetStorage->exists($relocation->target_key)) {
                $sourceStorage->delete($relocation->source_key);

                $file = $relocation->file;
                $derivatives = (array) ($file->derivatives ?? []);
                foreach ($derivatives as $key) {
                    if (is_string($key) && $sourceStorage->exists($key)) {
                        $sourceStorage->delete($key);
                    }
                }

                $deletedCount++;
            }
        }

        $migration->update([
            'status' => 'completed',
            'source_cleaned_at' => now(),
        ]);

        return $deletedCount;
    }

    private function calculateSha256(Filesystem $storage, string $path): string
    {
        $stream = $storage->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException("Kan bestand niet lezen voor SHA-256 berekening: {$path}");
        }

        $context = hash_init('sha256');
        while (! feof($stream)) {
            $buffer = fread($stream, 1024 * 1024);
            if ($buffer !== false && $buffer !== '') {
                hash_update($context, $buffer);
            }
        }
        fclose($stream);

        return hash_final($context);
    }
}
