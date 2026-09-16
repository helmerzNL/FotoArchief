<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tag extends CatalogueModel
{
    /**
     * @return BelongsToMany<Asset, $this, AssetTag>
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_tags')->using(AssetTag::class)->withPivot('id')->withTimestamps();
    }

    /**
     * @return HasMany<TagSynonym, $this>
     */
    public function synonyms(): HasMany
    {
        return $this->hasMany(TagSynonym::class);
    }
}
