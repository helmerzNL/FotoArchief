<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Models\UserRecoveryCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RecoveryCodeService
{
    /**
     * @return array<int, string>
     */
    public function regenerate(User $user): array
    {
        $codes = [];
        DB::transaction(function () use ($user, &$codes): void {
            UserRecoveryCode::query()->where('user_id', $user->id)->delete();
            for ($i = 0; $i < (int) config('identity.recovery_code_count'); $i++) {
                $code = $this->newCode();
                $codes[] = $code;
                UserRecoveryCode::query()->create([
                    'user_id' => $user->id,
                    'code_hash' => Hash::make($this->normalise($code)),
                ]);
            }
        });

        return $codes;
    }

    public function consume(string $email, string $code): ?User
    {
        $normalised = $this->normalise($code);
        $user = User::query()->where('email', strtolower($email))->where('is_active', true)->first();
        if (! $user instanceof User) {
            Hash::check($normalised, Hash::make('not-a-real-recovery-code'));

            return null;
        }

        return DB::transaction(function () use ($user, $normalised): ?User {
            $codes = UserRecoveryCode::query()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->lockForUpdate()
                ->get();

            foreach ($codes as $recoveryCode) {
                if (Hash::check($normalised, $recoveryCode->code_hash)) {
                    $recoveryCode->forceFill(['used_at' => now()])->save();
                    $user->forceFill(['session_revoked_at' => now()])->save();

                    return $user;
                }
            }

            return null;
        });
    }

    private function newCode(): string
    {
        $hex = strtoupper(bin2hex(random_bytes(10)));

        return implode('-', str_split($hex, 5));
    }

    private function normalise(string $code): string
    {
        return strtoupper(str_replace([' ', '-'], '', $code));
    }
}
