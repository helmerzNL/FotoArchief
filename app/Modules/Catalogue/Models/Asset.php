<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Models;

use App\Models\User;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Ingest\Models\QuarantineUpload;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
}
