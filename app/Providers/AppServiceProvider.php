<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\ArchiveOperations\Services\SystemHeartbeatService;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Queue::before(function (JobProcessing $event): void {
            if ($event->connectionName === 'ingest') {
                app(SystemHeartbeatService::class)->record('worker', 'processing', [
                    'queue' => $event->job->getQueue(),
                    'job' => $event->job->resolveName(),
                ]);
            }
        });

        Queue::after(function (JobProcessed $event): void {
            if ($event->connectionName === 'ingest') {
                app(SystemHeartbeatService::class)->record('worker', 'processed', [
                    'queue' => $event->job->getQueue(),
                    'job' => $event->job->resolveName(),
                ]);
            }
        });

        Queue::failing(function (JobFailed $event): void {
            if ($event->connectionName === 'ingest') {
                app(SystemHeartbeatService::class)->record('worker', 'failed', [
                    'queue' => $event->job->getQueue(),
                    'job' => $event->job->resolveName(),
                    'exception' => $event->exception::class,
                ]);
            }
        });
    }
}
