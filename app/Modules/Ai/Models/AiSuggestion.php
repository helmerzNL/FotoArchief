<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\CatalogueModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSuggestion extends CatalogueModel
{
    public const string TYPE_DESCRIPTION = 'description';

    public const string TYPE_TAG = 'tag';

    public const string TYPE_OBJECT = 'object';

    public const string REVIEW_PENDING = 'pending';

    public const string REVIEW_ACCEPTED = 'accepted';

    public const string REVIEW_REJECTED = 'rejected';

    public const string REVIEW_SUPERSEDED = 'superseded';

    /** @var array<string, string> */
    protected $attributes = [
        'language' => 'nl',
        'review_status' => self::REVIEW_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'source_asset_lock_version' => 'integer',
            'confidence' => 'float',
            'evidence' => 'array',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AiRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
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
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
