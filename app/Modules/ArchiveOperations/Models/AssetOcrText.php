<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Models;

use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\CatalogueModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $asset_id
 * @property string $asset_file_id
 * @property string|null $extracted_text
 * @property string|null $edited_text
 * @property bool $is_edited
 * @property float|null $confidence
 * @property string $language
 * @property string|null $engine_version
 * @property string $status
 * @property string|null $error_message
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Asset $asset
 * @property-read AssetFile $file
 */
class AssetOcrText extends CatalogueModel
{
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_edited' => false,
        'status' => 'queued',
        'language' => 'nld+eng',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_edited' => 'boolean',
        'confidence' => 'float',
        'processed_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<AssetFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(AssetFile::class, 'asset_file_id');
    }

    public function getEffectiveText(): string
    {
        if ($this->is_edited && $this->edited_text !== null) {
            return $this->edited_text;
        }

        return $this->extracted_text ?? '';
    }
}
