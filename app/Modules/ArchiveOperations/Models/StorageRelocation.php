<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Models;

use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\CatalogueModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $storage_migration_id
 * @property string $asset_file_id
 * @property string $source_disk
 * @property string $target_disk
 * @property string $source_key
 * @property string $target_key
 * @property string $sha256
 * @property bool $is_verified
 * @property array<string, string>|null $verified_derivatives
 * @property CarbonImmutable|null $cutover_completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read StorageMigration $migration
 * @property-read AssetFile $file
 */
class StorageRelocation extends CatalogueModel
{
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'is_verified' => 'boolean',
        'verified_derivatives' => 'array',
        'cutover_completed_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<StorageMigration, $this>
     */
    public function migration(): BelongsTo
    {
        return $this->belongsTo(StorageMigration::class, 'storage_migration_id');
    }

    /**
     * @return BelongsTo<AssetFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(AssetFile::class, 'asset_file_id');
    }
}
