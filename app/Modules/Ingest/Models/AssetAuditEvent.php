<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Models;

use App\Modules\Catalogue\Models\CatalogueModel;

class AssetAuditEvent extends CatalogueModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}
