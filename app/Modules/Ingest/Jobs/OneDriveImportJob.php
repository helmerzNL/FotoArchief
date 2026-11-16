<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Jobs;

use App\Modules\ArchiveOperations\Jobs\OperationJob;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\Ingest\Services\OneDriveImportService;

/**
 * Imports (or only counts) the chosen OneDrive folder in bounded chunks. The work
 * itself lives in OneDriveImportService; this job only ties it to an OperationRun.
 */
class OneDriveImportJob extends OperationJob
{
    public const string TYPE = 'onedrive.import';

    protected function executeChunk(OperationRun $run): array
    {
        return app(OneDriveImportService::class)->runChunk($run, $this->chunkNumber >= self::MAX_CHUNKS - 1);
    }
}
