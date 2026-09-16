<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends CatalogueModel
{
    protected function casts(): array
    {
        return ['latitude' => 'decimal:6', 'longitude' => 'decimal:6'];
    }

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
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    /**
     * @return HasMany<LocationAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(LocationAlias::class);
    }

    /**
     * @return BelongsToMany<Asset, $this, AssetLocation>
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_locations')
            ->using(AssetLocation::class)
            ->withPivot(['id', 'relationship_type', 'confidence', 'verification_status', 'note'])
            ->withTimestamps();
    }

    /**
     * Get full breadcrumb path as string.
     */
    public function fullPath(): string
    {
        $path = [$this->name];
        $current = $this->parent;
        while ($current !== null) {
            array_unshift($path, $current->name);
            $current = $current->parent;
        }

        return implode(' › ', $path);
    }

    /**
     * Get all recursive descendant IDs to prevent cyclical hierarchies.
     *
     * @return array<int, string>
     */
    public function allDescendantIds(): array
    {
        $descendants = [];
        $queue = $this->children()->pluck('id')->all();

        while (! empty($queue)) {
            $currentId = array_shift($queue);
            $descendants[] = $currentId;
            $childIds = self::query()->where('parent_id', $currentId)->pluck('id')->all();
            foreach ($childIds as $cid) {
                $queue[] = $cid;
            }
        }

        return $descendants;
    }
}
