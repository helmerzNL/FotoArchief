<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class JobOutboxMessage extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
        ];
    }
}
