<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Models;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\CatalogueModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
