<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class RightsStatement extends CatalogueModel
{
    /**
     * @return HasMany<AssetRight, $this>
     */
    public function assetRights(): HasMany
    {
        return $this->hasMany(AssetRight::class);
    }
}
