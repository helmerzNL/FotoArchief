<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestoreDrill extends Model
{
    use HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<BackupRecord, $this> */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(BackupRecord::class, 'backup_record_id');
    }

    protected function casts(): array
    {
        return ['report' => 'array', 'finished_at' => 'immutable_datetime'];
    }
}
