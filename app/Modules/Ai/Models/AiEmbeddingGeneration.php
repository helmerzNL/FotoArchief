<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\Catalogue\Models\CatalogueModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiEmbeddingGeneration extends CatalogueModel
{
    public const string STATUS_BUILDING = 'building';

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_RETIRED = 'retired';

    protected function casts(): array
    {
        return [
            'dimensions' => 'integer',
            'capability_receipt' => 'array',
            'activated_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<AiEmbedding, $this> */
    public function embeddings(): HasMany
    {
        return $this->hasMany(AiEmbedding::class);
    }

    /** @return BelongsTo<OperationRun, $this> */
    public function operationRun(): BelongsTo
    {
        return $this->belongsTo(OperationRun::class);
    }
}
