<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Services;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;

class ReviewReceipt
{
    /** @param array<string, mixed> $payload */
    public function issue(User $user, string $purpose, array $payload): string
    {
        return Crypt::encryptString(json_encode(['user' => $user->id, 'purpose' => $purpose, 'expires' => now()->addHour()->timestamp, 'payload' => $payload], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function read(User $user, string $purpose, string $receipt): array
    {
        try {
            $value = json_decode(Crypt::decryptString($receipt), true, 32, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw ValidationException::withMessages(['receipt' => __('daily.receipt_invalid')]);
        }
        if (! is_array($value) || ($value['user'] ?? null) !== $user->id || ($value['purpose'] ?? null) !== $purpose
            || ! is_int($value['expires'] ?? null) || $value['expires'] < now()->timestamp || ! is_array($value['payload'] ?? null)) {
            throw ValidationException::withMessages(['receipt' => __('daily.receipt_invalid')]);
        }

        return $value['payload'];
    }
}
