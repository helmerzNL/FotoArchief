<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation link (App-Guidance user-management.md). Only the SHA-256 of the token is kept;
 * the link itself is shown once to the administrator who made it. Using an invitation deletes it.
 */
class UserInvitation extends Model
{
    use HasUlids;

    public const string KIND_NEW = 'new';

    public const string KIND_RESET = 'reset';

    /** A token is URL-safe base64 of 32 random bytes; older links used 48 letters and digits. */
    public const string TOKEN_PATTERN = '/^[A-Za-z0-9_-]{32,128}$/';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $attributes = [
        'kind' => self::KIND_NEW,
    ];

    protected function casts(): array
    {
        return [
            'role_ids' => 'array',
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * The existing user a reset invitation is for.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isReset(): bool
    {
        return $this->kind === self::KIND_RESET;
    }

    public function isAcceptable(): bool
    {
        if ($this->accepted_at !== null || ! CarbonImmutable::parse($this->expires_at)->isFuture()) {
            return false;
        }
        if ($this->isReset()) {
            $user = $this->user()->first();

            return $user instanceof User && $user->is_active;
        }

        return true;
    }

    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function looksLikeToken(mixed $token): bool
    {
        return is_string($token) && preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
