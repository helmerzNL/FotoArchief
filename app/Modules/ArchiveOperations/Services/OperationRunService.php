<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Models\User;
use App\Modules\Ai\Jobs\ProcessAiAnalysisJob;
use App\Modules\Ai\Jobs\ProcessAiIndexJob;
use App\Modules\ArchiveOperations\Jobs\CleanupOrphanUploadsJob;
use App\Modules\ArchiveOperations\Jobs\OperationJob;
use App\Modules\ArchiveOperations\Jobs\PurgeAssetsJob;
use App\Modules\ArchiveOperations\Jobs\RebuildDerivativesJob;
use App\Modules\ArchiveOperations\Jobs\StorageCleanupJob;
use App\Modules\ArchiveOperations\Jobs\StorageCopyJob;
use App\Modules\ArchiveOperations\Jobs\VerifyIntegrityJob;
use App\Modules\ArchiveOperations\Models\OperationRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

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
     * Re-queues a failed run, keeping its history and cursor so a partially completed
     * operation resumes instead of restarting.
     *
     * @param  class-string<OperationJob>  $jobClass
     */
    public function retryRun(OperationRun $run, string $jobClass): OperationRun
    {
        $changes = [
            'status' => OperationRun::STATUS_QUEUED,
            'error_message' => null,
            'claim_token' => null,
            'finished_at' => null,
        ];
        if (in_array($run->operation_type, [ProcessAiAnalysisJob::TYPE, ProcessAiIndexJob::TYPE], true)) {
            $changes = array_merge($changes, [
                'payload' => array_merge($run->payload ?? [], ['cursor' => 0]),
                'processed_items' => 0,
                'failed_items' => 0,
                'result' => null,
            ]);
        }
        $run->forceFill($changes)->save();

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
