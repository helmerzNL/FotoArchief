<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Models;

use App\Models\User;
use App\Modules\Catalogue\Models\CatalogueModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string|null $asset_id
 * @property string $accession_number
 * @property string $title
 * @property string|null $purged_by_user_id
 * @property string|null $reason
 * @property int $deleted_files_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $purgedBy
 */
class TrashPurgeLog extends CatalogueModel
{
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'deleted_files_count' => 'integer',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function purgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purged_by_user_id');
    }
}
