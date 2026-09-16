<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Collection extends CatalogueModel
{
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
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
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('title');
    }

    /**
     * @return BelongsToMany<Asset, $this, CollectionAsset>
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'collection_assets')
            ->using(CollectionAsset::class)
            ->withPivot(['id', 'position', 'note'])
            ->withTimestamps()
            ->orderByPivot('position')
            ->orderBy('assets.id');
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

    /**
     * Public collection routes bind by slug, matching the archive-facing
     * permalink style used for published photos.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
