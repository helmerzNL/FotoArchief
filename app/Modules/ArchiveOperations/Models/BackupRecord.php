<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $version
 * @property string $location
 * @property string $manifest_sha256
 * @property int $byte_size
 * @property int $manifest_schema_version
 * @property string $backup_format
 * @property array<string, mixed>|null $manifest
 */
class BackupRecord extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'checksum_verified_at' => 'immutable_datetime',
            'byte_size' => 'integer',
            'manifest_schema_version' => 'integer',
            'manifest' => 'array',
        ];
    }

    /** @return HasMany<RestoreDrill, $this> */
    public function drills(): HasMany
    {
        return $this->hasMany(RestoreDrill::class);
    }
}
