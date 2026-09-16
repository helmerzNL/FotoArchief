<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Models;

use App\Models\User;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Ingest\Models\QuarantineUpload;
use App\Modules\Publication\Models\AssetSuggestion;
use App\Modules\Publication\Models\Publication;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

/**
 * @property string $id
 * @property string $accession_number
 * @property string $title
 * @property string|null $description
 * @property string|null $created_by_user_id
 * @property string|null $deleted_by_user_id
 * @property string|null $deletion_reason
 * @property CarbonImmutable|null $deleted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $deletedBy
 */
class Asset extends CatalogueModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'date_earliest' => 'date:Y-m-d',
            'date_latest' => 'date:Y-m-d',
            'lock_version' => 'integer',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    /** @return HasMany<QuarantineUpload, $this> */
    public function uploads(): HasMany
    {
        return $this->hasMany(QuarantineUpload::class);
    }

    /** @return HasMany<AssetAuditEvent, $this> */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AssetAuditEvent::class);
    }

    /**
     * @return HasMany<AssetFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(AssetFile::class);
    }

    /**
     * The single file every public route (predicate, viewer, media stream,
     * IIIF manifest) must agree is "the" current publishable original for
     * this asset. Requires `$this->files` to already be eager-loaded by the
     * caller (never issues its own query), so it can never diverge from
     * whatever the SQL-level predicate in
     * {@see Publication::scopePubliclyVisible()}
     * already validated.
     *
     * Deliberately returns null - fail closed - unless exactly one file is
     * both eligible (`ingest_status = 'ready_private'` and
     * `scanner_status = 'clean'`) and, once Operations' real
     * `asset_files.is_primary` column exists (see
     * docs/CONTRACT_ACTIVE_FILE.md), flagged primary. The database itself
     * enforces "at most one primary file per asset" with the partial unique
     * index `asset_files_single_primary_per_asset`
     * (migration `2026_09_17_240000_enforce_single_primary_asset_file.php`),
     * so this can only ever disagree with the predicate below by having
     * *zero* eligible files (e.g. mid-processing, or a since-revoked scan
     * status) - it never has to arbitrate between two "primary" rows,
     * because the database already refuses that state.
     *
     * Switching which file is primary already bumps `assets.lock_version`
     * (see `FileVersionService::activateVersion()`/`ImageProcessor`, owned by
     * Operations), which forces re-review through the existing
     * `assets.lock_version = publications.published_lock_version` gate
     * before the new primary can go public - exactly like any other edit,
     * per `docs/CONTRACT_SOFT_DELETE.md`'s precedent.
     */
    public function currentPublicFile(): ?AssetFile
    {
        $eligible = $this->files->filter(
            fn (AssetFile $file): bool => $file->ingest_status === 'ready_private' && $file->scanner_status === 'clean'
        );
        if (Schema::hasColumn('asset_files', 'is_primary')) {
            $eligible = $eligible->filter(fn (AssetFile $file): bool => (bool) $file->getAttribute('is_primary'));
        }

        return $eligible->count() === 1 ? $eligible->first() : null;
    }

    /**
     * @return HasMany<AssetVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AssetVersion::class);
    }

    /**
     * @return BelongsToMany<Person, $this, AssetPerson>
     */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'asset_people')->using(AssetPerson::class)->withPivot(['id', 'relationship_type', 'confidence', 'verification_status', 'note'])->withTimestamps();
    }

    /**
     * @return BelongsToMany<Location, $this, AssetLocation>
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'asset_locations')->using(AssetLocation::class)->withPivot(['id', 'relationship_type', 'confidence', 'verification_status', 'note'])->withTimestamps();
    }

    /**
     * @return BelongsToMany<Tag, $this, AssetTag>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'asset_tags')->using(AssetTag::class)->withPivot('id')->withTimestamps();
    }

    /**
     * @return BelongsToMany<Collection, $this, CollectionAsset>
     */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'collection_assets')->using(CollectionAsset::class)->withPivot(['id', 'position', 'note'])->withTimestamps();
    }

    /**
     * @return BelongsToMany<Source, $this, AssetSource>
     */
    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class, 'asset_sources')->using(AssetSource::class)->withPivot(['id', 'relationship_type', 'confidence', 'verification_status', 'note'])->withTimestamps();
    }

    /**
     * @return BelongsToMany<Contributor, $this, AssetContributor>
     */
    public function contributors(): BelongsToMany
    {
        return $this->belongsToMany(Contributor::class, 'asset_contributors')->using(AssetContributor::class)->withPivot(['id', 'relationship_type', 'confidence', 'verification_status', 'note'])->withTimestamps();
    }

    /**
     * @return HasMany<AssetRight, $this>
     */
    public function rights(): HasMany
    {
        return $this->hasMany(AssetRight::class);
    }

    /**
     * @return HasOne<Publication, $this>
     */
    public function publication(): HasOne
    {
        return $this->hasOne(Publication::class);
    }

    /**
     * @return HasMany<AssetSuggestion, $this>
     */
    public function suggestions(): HasMany
    {
        return $this->hasMany(AssetSuggestion::class);
    }
}
