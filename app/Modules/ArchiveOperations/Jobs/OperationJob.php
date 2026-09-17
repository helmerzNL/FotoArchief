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

    /**
     * A claim is reclaimable once it is this old. It must sit strictly between the
     * job timeout (120s, after which no worker can still be running the chunk) and
     * the ingest retry_after (180s, when the queue redelivers the message). If it
     * were equal to retry_after, a redelivery could arrive while the row was not yet
     * stale, fail to claim, and be deleted -- leaving the run "running" forever with
     * nothing left to retry it.
     */
    public const int STALE_CLAIM_SECONDS = 150;

    /** How long a duplicate delivery waits before trying the claim again. */
    public const int RECLAIM_DELAY_SECONDS = 30;

    /** Items handled by a single job before it re-dispatches the remainder. */
    public const int CHUNK_SIZE = 25;

    /**
     * Upper bound on the continuation chain. At CHUNK_SIZE 25 this covers 62 500
     * items, so a 50 000-file maintenance run completes in one go. Reaching the cap
     * is treated as a failure with a resume cursor, never as success.
     */
    public const int MAX_CHUNKS = 2500;

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
                            ->where('started_at', '<', now()->subSeconds(self::STALE_CLAIM_SECONDS));
                    });
            })
            ->update([
                'status' => OperationRun::STATUS_RUNNING,
                'started_at' => now(),
                'claim_token' => $token,
                'attempts' => DB::raw('attempts + 1'),
            ]);

        if ($claimed !== 1) {
            $this->handleUnclaimed();

            return;
        }

        $run = OperationRun::query()->find($this->runId);
        if (! $run instanceof OperationRun) {
            return;
        }

        try {
            $outcome = $this->executeChunk($run);

            if ($run->status === OperationRun::STATUS_CANCELLED) {
                return;
            }

            $run->forceFill([
                'processed_items' => $run->processed_items + $outcome['processed'],
                'failed_items' => $run->failed_items + $outcome['failed'],
            ])->save();

            if ($outcome['finished']) {
                if ($run->processed_items === 0 && $run->failed_items > 0) {
                    $run->forceFill([
                        'status' => OperationRun::STATUS_FAILED,
                        'claim_token' => null,
                        'finished_at' => now(),
                        'result' => array_merge($outcome['result'] ?? [], [
                            'processed' => $run->processed_items,
                            'failed' => $run->failed_items,
                            'truncated' => false,
                        ]),
                        'error_message' => $run->error_message ?? __('operations.generated.t_fe6dbc5f320d76f2'),
                    ])->save();

                    return;
                }

                $run->forceFill([
                    'status' => OperationRun::STATUS_COMPLETED,
                    'claim_token' => null,
                    'finished_at' => now(),
                    'result' => array_merge($outcome['result'] ?? [], [
                        'processed' => $run->processed_items,
                        'failed' => $run->failed_items,
                        'truncated' => false,
                    ]),
                ])->save();

                return;
            }

            // Work remains but the chain is at its bound, or a chunk made no progress
            // at all. Either way the operation did not do what was asked, so it must
            // not be recorded as completed: a run marked "completed" that silently
            // skipped 40 000 files is worse than a run that failed, because nobody
            // goes looking. The cursor stays on the run so it can be resumed.
            $stalled = $outcome['processed'] === 0 && $outcome['failed'] === 0;
            if ($this->chunkNumber >= self::MAX_CHUNKS || $stalled) {
                $run->forceFill([
                    'status' => OperationRun::STATUS_FAILED,
                    'claim_token' => null,
                    'finished_at' => now(),
                    'result' => array_merge($outcome['result'] ?? [], [
                        'processed' => $run->processed_items,
                        'failed' => $run->failed_items,
                        'truncated' => true,
                        'resume_cursor' => $run->payload['cursor'] ?? null,
                    ]),
                    'error_message' => $stalled
                        ? __('operations.generated.t_00f72eaf625b0a81')
                        : __('operations.generated.t_0dedf429a0bb87e1').(self::MAX_CHUNKS * self::CHUNK_SIZE).__('operations.generated.t_22e0aac3e7ccde63'),
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
                : __('operations.generated.t_e8f286da9110e7ea'),
        ])->save();
    }

    /**
     * Another delivery of this run could not take the claim.
     *
     * Deleting the message here is what strands an operation: if the holder was
     * killed between claiming and finishing, its own redelivery is the only thing
     * left that can resume the run, and a duplicate that silently disappears takes
     * that chance away. So the message is released back to the ingest queue while
     * the run is unfinished, and only dropped once the run has actually ended or
     * the retries are spent (the live holder then owns the continuation).
     */
    private function handleUnclaimed(): void
    {
        $run = OperationRun::query()->find($this->runId);

        if (! $run instanceof OperationRun || $run->isFinished()) {
            return;
        }

        if ($this->attempts() < $this->tries) {
            $this->release(self::RECLAIM_DELAY_SECONDS);
        }
    }

    private function describe(Throwable $exception): string
    {
        return Str::limit($exception->getMessage(), 950);
    }
}
