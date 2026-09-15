<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Models;

use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Ingest\Models\QuarantineUpload;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends CatalogueModel
{
    protected function casts(): array
    {
        return [
            'date_earliest' => 'date:Y-m-d',
            'date_latest' => 'date:Y-m-d',
            'lock_version' => 'integer',
        ];
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
