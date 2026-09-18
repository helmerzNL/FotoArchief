<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class RestoreDrill extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['report' => 'array', 'finished_at' => 'immutable_datetime'];
    }
}
