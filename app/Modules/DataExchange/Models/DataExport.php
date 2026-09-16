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
            'metadata_json' => 'Metadata (JSON)',
            'metadata_csv' => 'Metadata (CSV)',
            'package_zip' => 'Volledig pakket (ZIP met originelen)',
            default => $this->export_type,
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'queued' => 'In wachtrij',
            'running' => 'Bezig met samenstellen',
            'ready' => 'Klaar om te downloaden',
            'failed' => 'Mislukt',
            'expired' => 'Verlopen en opgeruimd',
            'revoked' => 'Ingetrokken: toegang is gewijzigd',
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
