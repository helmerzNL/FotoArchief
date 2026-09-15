<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Collection extends CatalogueModel
{
    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsToMany<Asset, $this, CollectionAsset>
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'collection_assets')->using(CollectionAsset::class)->withPivot(['id', 'position', 'note'])->withTimestamps();
    }
}
