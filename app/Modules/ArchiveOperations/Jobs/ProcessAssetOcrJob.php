<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Jobs;

use App\Modules\ArchiveOperations\Services\TesseractOcrService;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAssetOcrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Job timeout must stay at or below the ingest worker timeout (120s) because a
     * per-job timeout overrides the worker --timeout value, and it must stay below
     * the ingest connection retry_after (180s) to avoid duplicate reservation.
     */
    public const int MAX_JOB_TIMEOUT_SECONDS = 120;

    /**
     * Headroom reserved for reading the original from storage, writing the temporary
     * file and persisting the OCR record after the Tesseract process returns.
     */
    public const int PROCESS_TIMEOUT_HEADROOM_SECONDS = 20;

    public int $tries = 2;

    public int $timeout = self::MAX_JOB_TIMEOUT_SECONDS;

    public bool $failOnTimeout = true;

    public int $backoff = 10;

    public function __construct(
        public readonly string $assetFileId,
    ) {}

    public function handle(TesseractOcrService $ocrService): void
    {
        $file = AssetFile::find($this->assetFileId);
        if (! $file) {
            return;
        }

        $ocrService->processAssetFile($file);
    }
}
