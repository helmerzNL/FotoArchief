<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Models\WebAuthnChallenge;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class WebAuthnChallengeRepository
{
    public function store(string $purpose, string $challenge, ?User $user): WebAuthnChallenge
    {
        return WebAuthnChallenge::query()->create([
            'user_id' => $user?->id,
            'purpose' => $purpose,
            'challenge_hash' => WebAuthnChallenge::hashBinary($challenge),
            'challenge_ciphertext' => Crypt::encryptString(base64_encode($challenge)),
            'expires_at' => now()->addMinutes((int) config('identity.webauthn_challenge_ttl_minutes')),
        ]);
    }

    public function consume(string $id, string $purpose): WebAuthnChallenge
    {
        $challenge = WebAuthnChallenge::query()->whereKey($id)->lockForUpdate()->first();
        if (! $challenge instanceof WebAuthnChallenge || ! $challenge->isUsable($purpose)) {
            throw ValidationException::withMessages(['passkey' => 'De passkey-aanvraag is verlopen. Probeer opnieuw.']);
        }
        $challenge->forceFill(['consumed_at' => now()])->save();

        return $challenge;
    }
}
