<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\CatalogueModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiRun extends CatalogueModel
{
    public const string TYPE_IMAGE_ANALYSIS = 'image_analysis';

    public const string TYPE_EMBEDDING = 'embedding';

    public const string STATUS_QUEUED = 'queued';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_SUCCEEDED = 'succeeded';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_CANCELLED = 'cancelled';

    /** @var array<string, string> */
    protected $attributes = [
        'status' => self::STATUS_QUEUED,
    ];

    protected function casts(): array
    {
        return [
            'source_asset_lock_version' => 'integer',
            'input_contract' => 'array',
            'result_summary' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
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

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return HasMany<AiSuggestion, $this> */
    public function suggestions(): HasMany
    {
        return $this->hasMany(AiSuggestion::class);
    }

    public function matchesCurrentSource(AssetFile $file): bool
    {
        return $this->asset_file_id === $file->id
            && $this->asset_id === $file->asset_id
            && $this->source_file_sha256 === $file->sha256
            && $this->source_asset_lock_version === (int) $file->asset?->lock_version;
    }
}
