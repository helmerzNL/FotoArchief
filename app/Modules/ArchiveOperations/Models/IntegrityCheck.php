<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Models;

use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\CatalogueModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrityCheck extends CatalogueModel
{
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'details' => 'array',
        'resolved_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<AssetFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(AssetFile::class, 'asset_file_id');
    }
}
