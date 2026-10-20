<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Archive\Models\ArchiveProfile;
use App\Modules\ArchiveOperations\Services\SystemHeartbeatService;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        View::composer(['layouts.app', 'admin.dashboard'], function (ViewContract $view): void {
            $view->with('archiveProfile', ArchiveProfile::current());
        });

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
