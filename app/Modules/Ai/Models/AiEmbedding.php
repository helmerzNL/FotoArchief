<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\CatalogueModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiEmbedding extends CatalogueModel
{
    protected function casts(): array
    {
        return [
            'source_asset_lock_version' => 'integer',
            'embedding' => 'array',
            'metadata' => 'array',
            'indexed_at' => 'immutable_datetime',
            'stale_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AiEmbeddingGeneration, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(AiEmbeddingGeneration::class, 'ai_embedding_generation_id');
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<AssetFile, $this> */
    public function assetFile(): BelongsTo
    {
        return $this->belongsTo(AssetFile::class);
    }

    public function matchesCurrentSource(AssetFile $file): bool
    {
        return $this->asset_file_id === $file->id
            && $this->asset_id === $file->asset_id
            && $this->source_file_sha256 === $file->sha256
            && $this->source_asset_lock_version === (int) $file->asset?->lock_version;
    }
}
