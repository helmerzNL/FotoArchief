<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Support;

use App\Modules\Ingest\Models\QuarantineUpload;

/**
 * How many accepted uploads still wait for the scan and the previews. Bulk imports
 * (a server folder, OneDrive) pause while this reaches their limit, so a 500 GB
 * import never piles thousands of originals into quarantine at once.
 */
final class IngestBacklog
{
    public static function pending(): int
    {
        return QuarantineUpload::query()->whereIn('status', ['queued', 'running'])->count();
    }
}
