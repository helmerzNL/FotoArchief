<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Jobs;

use App\Modules\ArchiveOperations\Models\OperationRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Base for every heavy archive operation.
 *
 * Nothing that reads whole originals, rewrites derivatives, copies between disks or
 * destroys files may run inside an HTTP request: a single 32 MB scan on a slow disk
 * outlives any request timeout, and the operator is left without a status, an error
 * or a way to retry. Each operation is therefore a persistent OperationRun row driven
 * by a bounded queue job on the ingest connection.
 *
 * Bounded means: one job processes at most CHUNK_SIZE items and then re-dispatches
 * itself for the remainder, so a run of any size is made of jobs that each finish well
 * inside the worker timeout.
 */
abstract class OperationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Matches ProcessAssetOcrJob: a per-job timeout overrides the worker --timeout,
     * so it must not exceed 120s and must stay below ingest retry_after (180s).
     */
    public const int MAX_JOB_TIMEOUT_SECONDS = 120;

    /** Items handled by a single job before it re-dispatches the remainder. */
    public const int CHUNK_SIZE = 25;

    /** Guards against an endless continuation chain if progress ever stalls. */
    public const int MAX_CHUNKS = 400;

    public int $tries = 3;

    public int $timeout = self::MAX_JOB_TIMEOUT_SECONDS;

    public bool $failOnTimeout = true;

    public int $backoff = 10;

    /**
     * Final so a continuation may be constructed from the concrete job class; every
     * operation job is driven purely by its OperationRun row and needs no extra state.
     */
    final public function __construct(
        public readonly string $runId,
        public readonly int $chunkNumber = 1,
    ) {}

    /**
     * Process at most CHUNK_SIZE items.
     *
     * @return array{processed: int, failed: int, finished: bool, result?: array<string, mixed>}
     */
    abstract protected function executeChunk(OperationRun $run): array;

    public function handle(): void
    {
        $token = (string) Str::uuid();

        // Atomically claim the run; a stale 'running' row is reclaimable so a killed
        // worker cannot strand an operation forever.
        $claimed = OperationRun::query()
            ->whereKey($this->runId)
            ->where(function ($query): void {
                $query->where('status', OperationRun::STATUS_QUEUED)
                    ->orWhere(function ($query): void {
                        $query->where('status', OperationRun::STATUS_RUNNING)
                            ->where('started_at', '<', now()->subSeconds(self::MAX_JOB_TIMEOUT_SECONDS + 60));
                    });
            })
            ->update([
                'status' => OperationRun::STATUS_RUNNING,
                'started_at' => now(),
                'claim_token' => $token,
                'attempts' => DB::raw('attempts + 1'),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $run = OperationRun::query()->find($this->runId);
        if (! $run instanceof OperationRun) {
            return;
        }

        try {
            $outcome = $this->executeChunk($run);

            $run->forceFill([
                'processed_items' => $run->processed_items + $outcome['processed'],
                'failed_items' => $run->failed_items + $outcome['failed'],
            ])->save();

            $exhausted = $this->chunkNumber >= self::MAX_CHUNKS;

            if ($outcome['finished'] || $exhausted) {
                $run->forceFill([
                    'status' => OperationRun::STATUS_COMPLETED,
                    'claim_token' => null,
                    'finished_at' => now(),
                    'result' => array_merge($outcome['result'] ?? [], [
                        'processed' => $run->processed_items,
                        'failed' => $run->failed_items,
                        'truncated' => $exhausted && ! $outcome['finished'],
                    ]),
                ])->save();

                return;
            }

            // More work remains: release the claim and continue in a fresh bounded job.
            $run->forceFill([
                'status' => OperationRun::STATUS_QUEUED,
                'claim_token' => null,
            ])->save();

            Queue::connection('ingest')->push(new static($this->runId, $this->chunkNumber + 1));
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => OperationRun::STATUS_QUEUED,
                'claim_token' => null,
                'error_message' => $this->describe($exception),
            ])->save();

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = OperationRun::query()->find($this->runId);
        if (! $run instanceof OperationRun || $run->isFinished()) {
            return;
        }

        $run->forceFill([
            'status' => OperationRun::STATUS_FAILED,
            'claim_token' => null,
            'finished_at' => now(),
            'error_message' => $exception !== null
                ? $this->describe($exception)
                : 'De bewerking is mislukt. Controleer worker, opslag en rechten en probeer opnieuw.',
        ])->save();
    }

    private function describe(Throwable $exception): string
    {
        return Str::limit($exception->getMessage(), 950);
    }
}
