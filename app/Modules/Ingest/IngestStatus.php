<?php

declare(strict_types=1);

namespace App\Modules\Ingest;

enum IngestStatus: string
{
    case Quarantine = 'quarantine';
    case Validation = 'validation';
    case Scanning = 'scanning';
    case Processing = 'processing';
    case ReadyPrivate = 'ready_private';
    case Publishable = 'publishable';
    case Failed = 'failed';
}
