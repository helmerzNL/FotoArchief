<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Models;

use App\Modules\Ingest\Models\ProcessingJob;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class AssetFile extends CatalogueModel
{
    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'validated_at' => 'immutable_datetime',
            'scanned_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'publishable_at' => 'immutable_datetime',
            'technical_metadata' => 'array',
            'derivatives' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $file): void {
            if ($file->isDirty(['storage_key', 'sha256'])) {
                throw new LogicException('Asset file storage keys and checksums are immutable.');
            }
        });
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return HasMany<AssetVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AssetVersion::class);
    }

    /**
     * @return HasMany<ProcessingJob, $this>
     */
    public function processingJobs(): HasMany
    {
        return $this->hasMany(ProcessingJob::class);
    }
}
