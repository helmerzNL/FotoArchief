<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetVersion extends CatalogueModel
{
    protected function casts(): array
    {
        return ['version_number' => 'integer'];
    }

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
