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

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public int $backoff = 10;

    public function __construct(public readonly string $importId, public readonly string $mode)
    {
        if (! in_array($mode, ['analyse', 'apply'], true)) {
            throw new InvalidArgumentException('Unsupported import mode.');
        }
    }

    public function handle(MetadataImportService $service): void
    {
        $import = MetadataImport::query()->find($this->importId);
        if (! $import instanceof MetadataImport) {
            return;
        }
        $this->mode === 'analyse' ? $service->analyse($import) : $service->apply($import);
    }

    public function failed(?Throwable $exception): void
    {
        $import = MetadataImport::query()->find($this->importId);
        if ($import instanceof MetadataImport) {
            app(MetadataImportService::class)->markFailed($import, 'Verwerking mislukt. Controleer de worker en probeer opnieuw.');
        }
    }
}
