<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Models;

use App\Modules\Catalogue\Models\CatalogueModel;

class QuarantineUpload extends CatalogueModel
{
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'started_at' => 'immutable_datetime',
        'attempts' => 'integer',
        'byte_size' => 'integer',
    ];
}
