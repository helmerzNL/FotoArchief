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

    public function startMigration(string $sourceDisk, string $targetDisk, User $user): StorageMigration
    {
        if ($sourceDisk === $targetDisk) {
            throw new RuntimeException('Bron- en doelschijf mogen niet identiek zijn.');
        }

        $files = AssetFile::all();

        $migration = StorageMigration::create([
            'id' => (string) Str::ulid(),
            'source_disk' => $sourceDisk,
            'target_disk' => $targetDisk,
            'status' => 'verifying',
            'total_files' => $files->count(),
            'copied_files' => 0,
            'verified_files' => 0,
            'failed_files' => 0,
            'initiated_by_user_id' => $user->id,
        ]);

        $sourceStorage = Storage::disk($sourceDisk);
        $targetStorage = Storage::disk($targetDisk);

        $verifiedCount = 0;
        $failedCount = 0;

        foreach ($files as $file) {
            try {
                if (! $sourceStorage->exists($file->storage_key)) {
                    throw new RuntimeException("Bronbestand [{$file->storage_key}] ontbreekt op schijf [{$sourceDisk}].");
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

                // Copy derivatives if exists
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

                // Verify SHA-256 integrity on target disk
                $targetSha256 = $this->calculateSha256($targetStorage, $file->storage_key);
                if ($targetSha256 !== $file->sha256) {
                    throw new RuntimeException("Checksum mismatch op doel: verwacht {$file->sha256}, kreeg {$targetSha256}");
                }

                StorageRelocation::create([
                    'storage_migration_id' => $migration->id,
                    'asset_file_id' => $file->id,
                    'source_disk' => $sourceDisk,
                    'target_disk' => $targetDisk,
                    'source_key' => $file->storage_key,
                    'target_key' => $file->storage_key,
                    'sha256' => $targetSha256,
                    'is_verified' => true,
                    'cutover_completed_at' => null,
                ]);

                $verifiedCount++;
            } catch (\Throwable) {
                $failedCount++;
                StorageRelocation::create([
                    'storage_migration_id' => $migration->id,
                    'asset_file_id' => $file->id,
                    'source_disk' => $sourceDisk,
                    'target_disk' => $targetDisk,
                    'source_key' => $file->storage_key,
                    'target_key' => $file->storage_key,
                    'sha256' => $file->sha256,
                    'is_verified' => false,
                ]);
            }
        }

        $status = ($failedCount === 0 && $verifiedCount === $files->count()) ? 'verified' : 'failed_verification';
        $migration->update([
            'status' => $status,
            'copied_files' => $verifiedCount,
            'verified_files' => $verifiedCount,
            'failed_files' => $failedCount,
        ]);

        return $migration;
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
