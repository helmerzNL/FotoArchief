<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Services\OperationRunService;
use RuntimeException;

/**
 * Drives a queued archive operation to completion inside a test.
 *
 * Production runs each chunk as a separate queued job; a test needs the same
 * chunking without a worker, so the continuation chain is executed in-process.
 */
final class OperationRunDriver
{
    public static function drive(OperationRun|string $run, int $maxChunks = 50): OperationRun
    {
        $runId = $run instanceof OperationRun ? $run->id : $run;

        $fresh = OperationRun::query()->find($runId);
        if (! $fresh instanceof OperationRun) {
            throw new RuntimeException("Operation run {$runId} does not exist.");
        }

        $jobClass = OperationRunService::jobMap()[$fresh->operation_type] ?? null;
        if ($jobClass === null) {
            throw new RuntimeException("No job registered for operation type {$fresh->operation_type}.");
        }

        for ($chunk = 1; $chunk <= $maxChunks; $chunk++) {
            $job = new $jobClass($runId, $chunk);
            $job->handle();

            $fresh = OperationRun::query()->find($runId);
            if (! $fresh instanceof OperationRun) {
                throw new RuntimeException("Operation run {$runId} disappeared mid-flight.");
            }

            if ($fresh->isFinished()) {
                return $fresh;
            }
        }

        throw new RuntimeException("Operation run {$runId} did not finish within {$maxChunks} chunks.");
    }

    /**
     * Drives the most recent run of the given type, which is what a controller test has
     * after posting to an endpoint that only queues work.
     */
    public static function driveLatest(string $operationType, int $maxChunks = 50): OperationRun
    {
        $run = OperationRun::query()
            ->where('operation_type', $operationType)
            ->latest('created_at')
            ->first();

        if (! $run instanceof OperationRun) {
            throw new RuntimeException("No queued run found for operation type {$operationType}.");
        }

        return self::drive($run, $maxChunks);
    }
}
