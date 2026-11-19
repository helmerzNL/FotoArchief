<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One in-app backup (kind export or auto) or restore (kind restore). See
 * App\Modules\ArchiveOperations\Services\ArchiveBackupJobService. requested_by_user_id is a
 * plain column, not a foreign key, so nothing a restore does can cascade into this table.
 */
class BackupJob extends Model
{
    use HasUlids;

    public const string KIND_EXPORT = 'export';

    public const string KIND_AUTO = 'auto';

    public const string KIND_RESTORE = 'restore';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_DONE = 'done';

    public const string STATUS_FAILED = 'failed';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'error_params' => 'array',
            'summary' => 'array',
            'state' => 'array',
            'includes_files' => 'boolean',
            'step' => 'integer',
            'steps' => 'integer',
            'result_bytes' => 'integer',
            'checking_seconds' => 'float',
            'replacing_seconds' => 'float',
            'finished_at' => 'immutable_datetime',
            'dismissed_at' => 'immutable_datetime',
        ];
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }
}
