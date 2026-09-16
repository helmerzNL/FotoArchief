<?php

declare(strict_types=1);

namespace App\Modules\Publication\Models;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\CatalogueModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An anonymous visitor-submitted correction or identification suggestion for
 * a public photo. Accepting or rejecting a suggestion only ever records the
 * moderator's decision here; it never mutates asset metadata by itself, so
 * every metadata change still goes through the existing staff edit form and
 * its own audit trail.
 */
class AssetSuggestion extends CatalogueModel
{
    protected function casts(): array
    {
        return [
            'moderated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<Publication, $this>
     */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_user_id');
    }
}
