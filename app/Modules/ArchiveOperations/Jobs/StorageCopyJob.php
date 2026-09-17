<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Jobs;

use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Models\StorageMigration;
use App\Modules\ArchiveOperations\Services\StorageMigrationService;

/**
 * Copies originals and derivatives to the target disk and verifies each checksum.
 *
 * Copying an archive to S3 over a slow link cannot be an HTTP request at any size,
 * so the request only records the migration and this job advances it in chunks.
 */
class StorageCopyJob extends OperationJob
{
    public const string TYPE = 'storage.copy';

    protected function executeChunk(OperationRun $run): array
    {
        $payload = $run->payload ?? [];
        $migrationId = is_string($payload['migration_id'] ?? null) ? $payload['migration_id'] : null;
        $migration = $migrationId !== null ? StorageMigration::query()->find($migrationId) : null;

        if (! $migration instanceof StorageMigration) {
            return ['processed' => 0, 'failed' => 0, 'finished' => true];
        }

        $service = app(StorageMigrationService::class);
        $cursor = is_string($payload['cursor'] ?? null) ? $payload['cursor'] : null;
        $chunk = $service->relocateChunk($migration, $cursor, self::CHUNK_SIZE);

        $migration->refresh();
        $run->forceFill([
            'payload' => array_merge($payload, ['cursor' => $chunk['last_id']]),
            'processed_items' => $migration->verified_files,
            'failed_items' => $migration->failed_files,
        ])->save();

        if ($chunk['finished']) {
            $finalized = $service->finalizeMigration($migration);

            return [
                'processed' => $chunk['processed'],
                'processed_total' => $run->processed_items,
                'failed' => $chunk['failed'],
                'failed_total' => $run->failed_items,
                'finished' => true,
                'result' => [
                    'migration_id' => $finalized->id,
                    'migration_status' => $finalized->status,
                    'verified_files' => $finalized->verified_files,
                    'failed_files' => $finalized->failed_files,
                ],
            ];
        }

        return [
            'processed' => $chunk['processed'],
            'processed_total' => $run->processed_items,
            'failed' => $chunk['failed'],
            'failed_total' => $run->failed_items,
            'finished' => false,
        ];
    }
}
