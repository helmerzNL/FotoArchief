<?php

declare(strict_types=1);

namespace App\Modules\DataExchange\Models;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetadataImport extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'row_count' => 'integer',
            'attempts' => 'integer',
            'column_mapping' => 'array',
            'summary' => 'array',
            'started_at' => 'immutable_datetime',
            'analysed_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<MetadataImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(MetadataImportRow::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'received' => 'Ontvangen',
            'analysing' => __('exchange.generated.t_6f387d9245f0824c'),
            'analysed' => __('exchange.generated.t_daa62924e3c339a7'),
            'queued' => __('exchange.generated.t_f928e478af8c5479'),
            'running' => __('exchange.generated.t_41ebd3da30a6d5ac'),
            'completed' => 'Afgerond',
            'failed' => 'Mislukt',
            default => $this->status,
        };
    }

    public function isBusy(): bool
    {
        return in_array($this->status, ['analysing', 'queued', 'running'], true);
    }

    /**
     * Date casts are typed as string by static analysis, so every timestamp
     * comparison goes through this narrowing helper.
     */
    public function timestamp(string $attribute): ?DateTimeInterface
    {
        $value = $this->getAttribute($attribute);

        return $value instanceof DateTimeInterface ? $value : null;
    }
}
