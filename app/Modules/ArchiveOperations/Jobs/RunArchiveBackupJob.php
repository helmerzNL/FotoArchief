<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Jobs;

use App\Modules\ArchiveOperations\Models\BackupJob;
use App\Modules\ArchiveOperations\Services\ArchiveBackupJobService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Carries one step of an in-app backup or restore, then queues the next. A step stays well
 * inside the worker's two-minute limit (operations.backup.step_seconds of work), except the
 * replacing step of a restore, which must be one transaction. A redelivered copy of a step
 * that is still running finds the lock taken and leaves.
 */
class RunArchiveBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 115;

    public function __construct(public readonly string $backupJobId) {}

    public function handle(ArchiveBackupJobService $service): void
    {
        $lock = Cache::lock('operations.backup.job.'.$this->backupJobId, 170);
        if (! $lock->get()) {
            return;
        }
        try {
            $job = BackupJob::query()->find($this->backupJobId);
            if (! $job instanceof BackupJob || ! $job->isRunning()) {
                return;
            }
            $job->touch();
            $more = $service->step($job);
        } finally {
            $lock->release();
        }
        if ($more) {
            $service->dispatch($job);
        }
    }
}
