<?php

declare(strict_types=1);

namespace App\Modules\Publication\Models;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\CatalogueModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * The publication record is the single source of truth for whether an asset
 * may ever be served on a public route. Every public and IIIF controller
 * must read visibility through {@see scopePubliclyVisible()} so that rights,
 * privacy, embargo, malware-scan and edit-invalidation checks stay identical
 * everywhere, instead of being re-implemented (and drifting) per controller.
 */
class Publication extends CatalogueModel
{
    protected function casts(): array
    {
        return [
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'embargo_until' => 'date',
            'privacy_cleared' => 'boolean',
            'published_lock_version' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * The live public-authorization predicate. A publication is only public
     * when it is published, privacy-screened, past any embargo, still backed
     * by a scanned-clean ready-private file, still has verified rights, and
     * the asset has not been edited since the reviewed snapshot was published
     * (an edit after publish must force re-review before it can leak again).
     *
     * @param  Builder<Publication>  $query
     * @return Builder<Publication>
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        $query->where('publications.status', 'published')
            ->where('publications.privacy_cleared', true)
            ->where(function (Builder $q): void {
                $q->whereNull('publications.embargo_until')->orWhereDate('publications.embargo_until', '<=', now()->toDateString());
            })
            ->whereHas('asset', function (Builder $q): void {
                $q->whereColumn('assets.lock_version', 'publications.published_lock_version');
            })
            ->whereHas('asset.rights', function (Builder $q): void {
                $q->where('verification_status', 'verified');
            })
            ->whereHas('asset.files', function (Builder $q): void {
                $q->where('ingest_status', 'ready_private')->where('scanner_status', 'clean');
            });

        // Forward-compatible cross-module guard (see
        // docs/CONTRACT_SOFT_DELETE.md): Operations owns adding a recoverable
        // `assets.deleted_at` column later. The moment that column exists,
        // every public predicate here must deny a deleted asset without a
        // second coordinated change. Until then this is a no-op against the
        // current schema, so it cannot break anything today.
        if (Schema::hasColumn('assets', 'deleted_at')) {
            $query->whereHas('asset', function (Builder $q): void {
                $q->whereNull('assets.deleted_at');
            });
        }

        return $query;
    }

    /**
     * True when the asset was edited after the last publish decision, so the
     * public predicate above will already hide it until it is re-reviewed.
     */
    public function needsReReview(): bool
    {
        if ($this->status !== 'published') {
            return false;
        }

        return $this->asset?->lock_version !== $this->published_lock_version;
    }

    /**
     * Public routes bind by the stable permalink slug, never by the internal
     * ULID, and every such binding is resolved through the same eligibility
     * scope so a private, embargoed or revoked publication 404s instead of
     * leaking through route-model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'permalink_slug';
    }

    /**
     * @param  mixed  $value
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->publiclyVisible()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }
}
