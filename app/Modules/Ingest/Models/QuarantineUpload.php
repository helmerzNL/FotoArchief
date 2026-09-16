<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Models;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\CatalogueModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string|null $asset_id
 * @property string|null $uploaded_by_user_id
 * @property string $storage_disk
 * @property string $storage_key
 * @property string|null $original_filename
 * @property int $byte_size
 * @property string $status
 * @property string|null $failure_reason
 * @property int $attempts
 * @property CarbonImmutable|null $started_at
 * @property string|null $claim_token
 * @property string|null $duplicate_of_asset_id
 * @property string|null $duplicate_of_file_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Asset|null $asset
 * @property-read User|null $uploadedBy
 * @property-read Asset|null $duplicateOfAsset
 * @property-read AssetFile|null $duplicateOfFile
 */
class QuarantineUpload extends CatalogueModel
{
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'started_at' => 'immutable_datetime',
        'attempts' => 'integer',
        'byte_size' => 'integer',
    ];

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
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function duplicateOfAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'duplicate_of_asset_id');
    }

    /**
     * @return BelongsTo<AssetFile, $this>
     */
    public function duplicateOfFile(): BelongsTo
    {
        return $this->belongsTo(AssetFile::class, 'duplicate_of_file_id');
    }
}
