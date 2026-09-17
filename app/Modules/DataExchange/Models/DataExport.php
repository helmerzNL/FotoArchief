<?php

declare(strict_types=1);

namespace App\Modules\DataExchange\Models;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<string>|null $asset_ids
 * @property array<string, mixed>|null $manifest
 */
class DataExport extends Model
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
            'asset_ids' => 'array',
            'manifest' => 'array',
            'asset_count' => 'integer',
            'byte_size' => 'integer',
            'download_count' => 'integer',
            'attempts' => 'integer',
            'started_at' => 'immutable_datetime',
            'download_token_expires_at' => 'immutable_datetime',
            'last_downloaded_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return list<string>
     */
    public function assetIds(): array
    {
        return $this->asset_ids ?? [];
    }

    public function typeLabel(): string
    {
        return match ($this->export_type) {
            'metadata_json' => __('exchange.generated.t_e3d4087cf8e47603'),
            'metadata_csv' => __('exchange.generated.t_64388ab124349776'),
            'package_zip' => __('exchange.generated.t_3cc628c35a3d66de'),
            default => $this->export_type,
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'queued' => __('exchange.generated.t_9b88bb032d925e75'),
            'running' => __('exchange.generated.t_06b990a47248198c'),
            'ready' => __('exchange.generated.t_66334e296995884b'),
            'failed' => 'Mislukt',
            'expired' => __('exchange.generated.t_467c15013f892cae'),
            'revoked' => __('exchange.generated.t_9e2fb48acaa1deef'),
            default => $this->status,
        };
    }

    public function isBusy(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    public function isDownloadable(): bool
    {
        $expiry = $this->timestamp('expires_at');

        return $this->status === 'ready'
            && $this->storage_key !== null
            && $expiry !== null
            && $expiry->getTimestamp() > time();
    }

    /**
     * Compares a presented download token against the stored hash in constant
     * time and only while the token is still inside its short validity window.
     */
    public function hasValidDownloadToken(string $token): bool
    {
        $hash = $this->download_token_hash;
        $expiry = $this->timestamp('download_token_expires_at');

        return is_string($hash)
            && $expiry !== null
            && $expiry->getTimestamp() > time()
            && hash_equals($hash, hash('sha256', $token));
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
