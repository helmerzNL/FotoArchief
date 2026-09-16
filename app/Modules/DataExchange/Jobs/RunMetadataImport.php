<?php

declare(strict_types=1);

namespace App\Modules\DataExchange\Jobs;

use App\Modules\DataExchange\Models\MetadataImport;
use App\Modules\DataExchange\Services\MetadataImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use Throwable;

class RunMetadataImport implements ShouldQueue
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

    public function __construct(public readonly string $importId, public readonly string $mode)
    {
        if (! in_array($mode, ['analyse', 'apply'], true)) {
            throw new InvalidArgumentException('Unsupported import mode.');
        }
        $this->timeout = (int) config('exchange.job_timeout_seconds');
    }

    public function handle(MetadataImportService $service): void
    {
        $import = MetadataImport::query()->find($this->importId);
        if (! $import instanceof MetadataImport) {
            return;
        }
        $settled = $this->mode === 'analyse' ? $service->analyse($import) : $service->apply($import);
        if (! $settled) {
            // Another worker still holds a live claim. Reporting success here
            // would delete the only job that can finish this import, so come
            // back once the claim can be taken over instead.
            $this->release((int) config('exchange.stale_claim_seconds'));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $import = MetadataImport::query()->find($this->importId);
        if ($import instanceof MetadataImport) {
            app(MetadataImportService::class)->markFailed($import, 'Verwerking mislukt. Controleer de worker en probeer opnieuw.');
        }
    }
}
