<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Models\User;
use App\Modules\Ai\Jobs\ProcessAiAnalysisJob;
use App\Modules\Ai\Jobs\ProcessAiIndexJob;
use App\Modules\Ai\Services\AiAssetBatchService;
use App\Modules\ArchiveOperations\Jobs\CleanupOrphanUploadsJob;
use App\Modules\ArchiveOperations\Jobs\OperationJob;
use App\Modules\ArchiveOperations\Jobs\PurgeAssetsJob;
use App\Modules\ArchiveOperations\Jobs\RebuildDerivativesJob;
use App\Modules\ArchiveOperations\Jobs\StorageCleanupJob;
use App\Modules\ArchiveOperations\Jobs\StorageCopyJob;
use App\Modules\ArchiveOperations\Jobs\VerifyIntegrityJob;
use App\Modules\ArchiveOperations\Models\OperationRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Single entry point for starting heavy archive operations.
 *
 * Every caller records an OperationRun and pushes a bounded job onto the ingest
 * connection; no checksum, rebuild, storage-copy or purge work runs in the request.
 */
class OperationRunService
{
    /**
     * @param  class-string<OperationJob>  $jobClass
     * @param  array<string, mixed>  $payload
     */
    public function dispatchRun(string $jobClass, string $type, User $user, array $payload = [], int $totalItems = 0): OperationRun
    {
        $run = OperationRun::create([
            'id' => (string) Str::ulid(),
            'operation_type' => $type,
            'status' => OperationRun::STATUS_QUEUED,
            'requested_by_user_id' => $user->id,
            'payload' => $payload,
            'total_items' => $totalItems,
        ]);

        Queue::connection('ingest')->push(new $jobClass($run->id));

        return $run;
    }

    /**
     * Re-queues a failed run, preserving history. AI runs validate their references
     * and restart; other operations retain their resume cursor.
     *
     * @param  class-string<OperationJob>  $jobClass
     */
    public function retryRun(OperationRun $run, string $jobClass, ?User $user = null): OperationRun
    {
        $changes = [
            'status' => OperationRun::STATUS_QUEUED,
            'error_message' => null,
            'claim_token' => null,
            'finished_at' => null,
        ];
        $normalization = null;
        if (in_array($run->operation_type, [ProcessAiAnalysisJob::TYPE, ProcessAiIndexJob::TYPE], true)) {
            $user ??= $run->requestedBy;
            if (! $user instanceof User) {
                throw ValidationException::withMessages(['asset_ids' => 'De aanvrager van deze AI-taak bestaat niet meer. Start een nieuwe taak.']);
            }
            $payload = $run->payload ?? [];
            $references = $payload['asset_ids'] ?? null;
            $format = $payload['asset_id_format'] ?? 'legacy';
            if (! is_array($references) || ! is_string($format)) {
                throw ValidationException::withMessages(['asset_ids' => 'Deze AI-taak bevat ongeldige fotoreferenties. Start een nieuwe taak.']);
            }
            $batch = app(AiAssetBatchService::class)->normalize($references, $user, $format);
            if ($format !== AiAssetBatchService::INTERNAL_FORMAT) {
                $normalization = $batch['references'];
            }
            $changes = array_merge($changes, [
                'payload' => array_merge($payload, [
                    'asset_ids' => $batch['asset_ids'],
                    'asset_id_format' => AiAssetBatchService::INTERNAL_FORMAT,
                    'cursor' => 0,
                ]),
                'total_items' => count($batch['asset_ids']),
                'processed_items' => 0,
                'failed_items' => 0,
                'result' => null,
            ]);
        }
        DB::transaction(function () use ($run, $changes, $normalization, $user): void {
            $run->forceFill($changes)->save();
            if ($normalization !== null) {
                $run->auditEvents()->create([
                    'event_type' => $run->operation_type.'.references_normalized',
                    'severity' => 'info',
                    'message' => 'Fotoreferenties gecontroleerd en omgezet naar interne IDs voor een herpoging.',
                    'context' => ['references' => $normalization, 'retried_by_user_id' => $user?->id],
                ]);
            }
        });

        Queue::connection('ingest')->push(new $jobClass($run->id));

        return $run;
    }

    /**
     * Cancels a run that is still queued, so a worker that later claims it finds a
     * non-queued status and stops without doing any work. Running or finished runs
     * cannot be cancelled: there is no cooperative mid-chunk interrupt, so pretending
     * to stop a run that is already executing would be misleading.
     */
    public function cancelRun(OperationRun $run): OperationRun
    {
        $run->forceFill([
            'status' => OperationRun::STATUS_CANCELLED,
            'claim_token' => null,
            'finished_at' => now(),
        ])->save();

        return $run;
    }

    /**
     * @return LengthAwarePaginator<int, OperationRun>
     */
    public function recentRuns(int $perPage = 20): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, OperationRun> $runs */
        $runs = OperationRun::query()
            ->with([
                'requestedBy',
                'auditEvents' => fn ($query) => $query->latest('created_at')->limit(10),
            ])
            ->latest('created_at')
            ->paginate($perPage);

        return $runs;
    }

    /**
     * @return array<string, class-string<OperationJob>>
     */
    public static function jobMap(): array
    {
        return [
            VerifyIntegrityJob::TYPE => VerifyIntegrityJob::class,
            RebuildDerivativesJob::TYPE => RebuildDerivativesJob::class,
            StorageCopyJob::TYPE => StorageCopyJob::class,
            StorageCleanupJob::TYPE => StorageCleanupJob::class,
            PurgeAssetsJob::TYPE => PurgeAssetsJob::class,
            CleanupOrphanUploadsJob::TYPE => CleanupOrphanUploadsJob::class,
            ProcessAiAnalysisJob::TYPE => ProcessAiAnalysisJob::class,
            ProcessAiIndexJob::TYPE => ProcessAiIndexJob::class,
        ];
    }
}
