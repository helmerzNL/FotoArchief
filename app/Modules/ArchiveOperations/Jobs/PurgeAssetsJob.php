<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Jobs;

use App\Models\User;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Services\TrashService;
use App\Modules\Catalogue\Models\Asset;
use Illuminate\Support\Str;
use Throwable;

/**
 * Irreversibly destroys trashed assets and their files.
 *
 * Purging deletes real bytes; if the request dies half way the operator has no record
 * of what was destroyed. A persistent run makes the outcome auditable and retryable.
 *
 * Handles both a single confirmed asset and the retention sweep, because they differ
 * only in which assets are selected.
 */
class PurgeAssetsJob extends OperationJob
{
    public const string TYPE = 'trash.purge';

    protected function executeChunk(OperationRun $run): array
    {
        $payload = $run->payload ?? [];
        $actor = User::query()->find($run->requested_by_user_id);
        if (! $actor instanceof User) {
            return ['processed' => 0, 'failed' => 0, 'finished' => true];
        }

        $reason = is_string($payload['reason'] ?? null) ? $payload['reason'] : 'Definitieve verwijdering.';
        $assetId = is_string($payload['asset_id'] ?? null) ? $payload['asset_id'] : null;

        if ($assetId !== null) {
            $assets = Asset::onlyTrashed()->whereKey($assetId)->get();
        } else {
            $retentionDays = (int) ($payload['retention_days'] ?? TrashService::DEFAULT_RETENTION_DAYS);
            $assets = Asset::onlyTrashed()
                ->where('deleted_at', '<=', now()->subDays($retentionDays))
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE)
                ->get();
        }

        $service = app(TrashService::class);
        $processed = 0;
        $failed = 0;
        $errors = [];

        foreach ($assets as $asset) {
            try {
                $service->purgeAsset($asset, $reason, $actor);
                $processed++;
            } catch (Throwable $exception) {
                $failed++;
                $errors[$asset->id] = Str::limit($exception->getMessage(), 300);
            }
        }

        // A sweep that purged nothing this round has nothing left to purge; stopping
        // on $processed === 0 also prevents an endless retry of undeletable rows.
        $finished = $assetId !== null || $assets->count() < self::CHUNK_SIZE || $processed === 0;

        return [
            'processed' => $processed,
            'failed' => $failed,
            'finished' => $finished,
            'result' => $errors === [] ? [] : ['errors' => $errors],
        ];
    }
}
