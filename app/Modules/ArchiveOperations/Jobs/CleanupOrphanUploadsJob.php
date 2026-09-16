<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Jobs;

use App\Models\User;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Services\TrashService;

/**
 * Removes quarantine files left behind by failed or abandoned uploads.
 *
 * Deletes bytes from private storage, so it belongs on the queue with the other
 * destructive operations rather than in a request handler.
 */
class CleanupOrphanUploadsJob extends OperationJob
{
    public const string TYPE = 'trash.orphans';

    protected function executeChunk(OperationRun $run): array
    {
        $payload = $run->payload ?? [];
        $actor = User::query()->find($run->requested_by_user_id);
        if (! $actor instanceof User) {
            return ['processed' => 0, 'failed' => 0, 'finished' => true];
        }

        $retentionHours = (int) ($payload['retention_hours'] ?? 48);
        $cleaned = app(TrashService::class)->cleanupOrphanQuarantineUploads($retentionHours, $actor);

        return [
            'processed' => $cleaned,
            'failed' => 0,
            'finished' => true,
            'result' => ['cleaned_uploads' => $cleaned],
        ];
    }
}
