<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends CatalogueModel
{
    protected function casts(): array
    {
        return ['birth_date_earliest' => 'date', 'birth_date_latest' => 'date', 'death_date_earliest' => 'date', 'death_date_latest' => 'date'];
    }

    /**
     * @return HasMany<PersonAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(PersonAlias::class);
    }

    /**
     * @return BelongsToMany<Asset, $this, AssetPerson>
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_people')->using(AssetPerson::class)->withPivot(['id', 'relationship_type', 'confidence', 'verification_status', 'note'])->withTimestamps();
    }
}
