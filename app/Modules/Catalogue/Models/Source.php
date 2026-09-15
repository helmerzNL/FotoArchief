<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Source extends CatalogueModel
{
    /**
     * @return BelongsToMany<Asset, $this, AssetSource>
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_sources')->using(AssetSource::class)->withPivot(['id', 'relationship_type', 'confidence', 'verification_status', 'note'])->withTimestamps();
    }
}
