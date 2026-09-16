<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AiBudgetLedger extends Model
{
    use HasUlids;

    protected $table = 'ai_budget_ledgers';

    protected $fillable = [
        'period_key',
        'provider_kind',
        'capability',
        'cents_limit',
        'cents_reserved',
        'cents_consumed',
    ];

    protected function casts(): array
    {
        return [
            'cents_limit' => 'integer',
            'cents_reserved' => 'integer',
            'cents_consumed' => 'integer',
        ];
    }
}
