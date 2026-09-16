<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Jobs;

use App\Models\User;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Models\StorageMigration;
use App\Modules\ArchiveOperations\Services\StorageMigrationService;

/**
 * Deletes source files after a verified cutover.
 *
 * Destroying archive bytes is never synchronous HTTP work: the operator must be able
 * to see it start, fail and be retried rather than lose it to a dropped connection.
 */
class StorageCleanupJob extends OperationJob
{
    public const string TYPE = 'storage.cleanup';

    protected function executeChunk(OperationRun $run): array
    {
        $payload = $run->payload ?? [];
        $migrationId = is_string($payload['migration_id'] ?? null) ? $payload['migration_id'] : null;
        $migration = $migrationId !== null ? StorageMigration::query()->find($migrationId) : null;
        $actor = User::query()->find($run->requested_by_user_id);

        if (! $migration instanceof StorageMigration || ! $actor instanceof User) {
            return ['processed' => 0, 'failed' => 0, 'finished' => true];
        }

        $deleted = app(StorageMigrationService::class)->cleanupSourceFiles($migration, $actor);

        return [
            'processed' => $deleted,
            'failed' => 0,
            'finished' => true,
            'result' => ['deleted_source_files' => $deleted, 'migration_id' => $migration->id],
        ];
    }
}
