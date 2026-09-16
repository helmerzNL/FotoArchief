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

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public int $backoff = 10;

    public function __construct(public readonly string $exportId) {}

    public function handle(DataExportService $service): void
    {
        $export = DataExport::query()->find($this->exportId);
        if ($export instanceof DataExport) {
            $service->build($export);
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
