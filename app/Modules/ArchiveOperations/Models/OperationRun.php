<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Models;

use App\Models\User;
use App\Modules\Catalogue\Models\CatalogueModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $operation_type
 * @property string $status
 * @property string|null $requested_by_user_id
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $result
 * @property int $total_items
 * @property int $processed_items
 * @property int $failed_items
 * @property int $attempts
 * @property string|null $error_message
 * @property string|null $claim_token
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $requestedBy
 */
class OperationRun extends CatalogueModel
{
    public const string STATUS_QUEUED = 'queued';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_FAILED = 'failed';

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_QUEUED,
        'total_items' => 0,
        'processed_items' => 0,
        'failed_items' => 0,
        'attempts' => 0,
    ];

    /** @var array<string, string> */
    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'total_items' => 'integer',
        'processed_items' => 'integer',
        'failed_items' => 'integer',
        'attempts' => 'integer',
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }
}
