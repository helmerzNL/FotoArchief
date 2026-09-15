<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Contributor extends CatalogueModel
{
    /**
     * @return BelongsToMany<Asset, $this, AssetContributor>
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_contributors')->using(AssetContributor::class)->withPivot(['id', 'relationship_type', 'confidence', 'verification_status', 'note'])->withTimestamps();
    }
}
