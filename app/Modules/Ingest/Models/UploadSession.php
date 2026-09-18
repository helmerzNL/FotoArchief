<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Models;

use App\Modules\Catalogue\Models\CatalogueModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $user_id
 * @property string $client_key
 * @property string $manifest_sha256
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $purged_at
 * @property-read Collection<int, UploadSessionItem> $items
 */
class UploadSession extends CatalogueModel
{
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'purged_at' => 'immutable_datetime'];
    }

    /** @return HasMany<UploadSessionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(UploadSessionItem::class);
    }
}
