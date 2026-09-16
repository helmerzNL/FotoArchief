<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Models;

use App\Models\User;
use App\Modules\Catalogue\Models\CatalogueModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string|null $initiated_by_user_id
 * @property string $source_disk
 * @property string $target_disk
 * @property string $status
 * @property int $total_files
 * @property int $copied_files
 * @property int $verified_files
 * @property int $failed_files
 * @property CarbonImmutable|null $cutover_at
 * @property CarbonImmutable|null $source_cleaned_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $initiatedBy
 */
class StorageMigration extends CatalogueModel
{
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'total_files' => 'integer',
        'copied_files' => 'integer',
        'verified_files' => 'integer',
        'failed_files' => 'integer',
        'cutover_at' => 'immutable_datetime',
        'source_cleaned_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /**
     * @return HasMany<StorageRelocation, $this>
     */
    public function relocations(): HasMany
    {
        return $this->hasMany(StorageRelocation::class, 'storage_migration_id');
    }
}
