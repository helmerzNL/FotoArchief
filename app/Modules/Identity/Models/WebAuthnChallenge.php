<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class WebAuthnChallenge extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'webauthn_challenges';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function binaryChallenge(): string
    {
        return base64_decode(Crypt::decryptString($this->challenge_ciphertext), true) ?: '';
    }

    public function isUsable(string $purpose): bool
    {
        return $this->purpose === $purpose
            && $this->consumed_at === null
            && CarbonImmutable::parse($this->expires_at)->isFuture();
    }

    public static function hashBinary(string $challenge): string
    {
        return hash('sha256', $challenge);
    }
}
