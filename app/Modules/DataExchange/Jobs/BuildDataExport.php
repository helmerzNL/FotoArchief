<?php

declare(strict_types=1);

namespace App\Modules\DataExchange\Jobs;

use App\Modules\DataExchange\Models\DataExport;
use App\Modules\DataExchange\Services\DataExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class BuildDataExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Kept below the queue retry_after so a redelivered job never races a job
     * that is still running; see the window comment in config/exchange.php.
     */
    public int $timeout;

    public bool $failOnTimeout = true;

    public int $backoff = 10;

    public function __construct(public readonly string $exportId)
    {
        $this->timeout = (int) config('exchange.job_timeout_seconds');
    }

    public function handle(DataExportService $service): void
    {
        $export = DataExport::query()->find($this->exportId);
        if (! $export instanceof DataExport) {
            return;
        }
        if (! $service->build($export)) {
            // Another worker still holds a live claim. Reporting success here
            // would delete the only job that can finish this export, so come
            // back once the claim can be taken over instead.
            $this->release((int) config('exchange.stale_claim_seconds'));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $export = DataExport::query()->find($this->exportId);
        if ($export instanceof DataExport) {
            app(DataExportService::class)->markFailed($export, 'Samenstellen mislukt. Controleer de worker en probeer opnieuw.');
        }
    }
}
