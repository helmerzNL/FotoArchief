<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Models\UserInvitation;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invitation links and reset links, following App-Guidance shared/guidelines/user-management.md.
 *
 * - The administrator never learns a credential: the guest chooses a password or a passkey.
 * - Only the SHA-256 of the token is stored; the link is shown once.
 * - One use, seven days; expired rows are purged whenever an invitation is made.
 * - A reset changes nothing until the guest has set the new credentials.
 * - Creation, use and revocation are written to the application log (never the token).
 */
class InvitationService
{
    /**
     * @param  array<int, string>  $roleIds
     * @return array{invitation: UserInvitation, token: string}
     */
    public function invite(User $actor, string $name, string $email, array $roleIds): array
    {
        $email = strtolower(trim($email));
        $token = UserInvitation::newToken();

        $invitation = DB::transaction(function () use ($actor, $name, $email, $roleIds, $token): UserInvitation {
            $this->purgeExpired();
            if (User::query()->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => __('identity.generated.t_ddad49dc7440e5bf')]);
            }
            if ($this->pendingNew()->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => __('identity.invitations.errors.email_reserved')]);
            }
            $this->assertOpenInvitationCap();
            $users = User::query()->where('is_active', true)->count() + $this->pendingNew()->count();
            if ($users >= (int) config('identity.max_users')) {
                throw ValidationException::withMessages(['email' => __('identity.invitations.errors.user_cap', ['max' => (int) config('identity.max_users')])]);
            }

            return UserInvitation::query()->create([
                'kind' => UserInvitation::KIND_NEW,
                'name' => $name,
                'email' => $email,
                'token_hash' => UserInvitation::hashToken($token),
                'role_ids' => array_values(array_unique($roleIds)),
                'invited_by_user_id' => $actor->id,
                'expires_at' => $this->expiry(),
            ]);
        });
        $this->audit('identity.invitation.created', $actor, $invitation);

        return ['invitation' => $invitation, 'token' => $token];
    }

    /**
     * A reset link for an existing, active user. It replaces an earlier reset link for that user
     * and does not touch the current credentials.
     *
     * @return array{invitation: UserInvitation, token: string}
     */
    public function reset(User $actor, User $user): array
    {
        if ($actor->id === $user->id) {
            throw ValidationException::withMessages(['user' => __('identity.invitations.errors.reset_self')]);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['user' => __('identity.invitations.errors.reset_inactive')]);
        }
        $token = UserInvitation::newToken();

        $invitation = DB::transaction(function () use ($actor, $user, $token): UserInvitation {
            $this->purgeExpired();
            UserInvitation::query()->where('kind', UserInvitation::KIND_RESET)->where('user_id', $user->id)->delete();
            $this->assertOpenInvitationCap();

            return UserInvitation::query()->create([
                'kind' => UserInvitation::KIND_RESET,
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'token_hash' => UserInvitation::hashToken($token),
                'role_ids' => [],
                'invited_by_user_id' => $actor->id,
                'expires_at' => $this->expiry(),
            ]);
        });
        $this->audit('identity.invitation.reset_created', $actor, $invitation);

        return ['invitation' => $invitation, 'token' => $token];
    }

    public function revoke(User $actor, UserInvitation $invitation): void
    {
        $invitation->delete();
        $this->audit('identity.invitation.revoked', $actor, $invitation);
    }

    /** Unknown, expired, used and malformed tokens are all the same: null. */
    public function find(mixed $token): ?UserInvitation
    {
        if (! UserInvitation::looksLikeToken($token)) {
            return null;
        }
        $invitation = UserInvitation::query()->where('token_hash', UserInvitation::hashToken($token))->first();

        return $invitation instanceof UserInvitation && $invitation->isAcceptable() ? $invitation : null;
    }

    /**
     * The one way an invitation becomes an account: check again, validate, then create (or reset),
     * store the credential and delete the invitation, all in one transaction. A password and a
     * passkey both come through here, so a rule added later holds for both.
     *
     * @param  Closure(User): void  $storeCredential  writes the chosen credential for the user
     */
    public function complete(string $token, ?string $name, bool $withPassword, ?string $password, Closure $storeCredential, ?string $plannedUserId = null): User
    {
        try {
            $user = DB::transaction(function () use ($token, $name, $withPassword, $password, $storeCredential, $plannedUserId): User {
                $invitation = UserInvitation::looksLikeToken($token)
                    ? UserInvitation::query()->where('token_hash', UserInvitation::hashToken($token))->lockForUpdate()->first()
                    : null;
                if (! $invitation instanceof UserInvitation || ! $invitation->isAcceptable()) {
                    throw ValidationException::withMessages(['token' => __('identity.generated.t_764daa1c66b4c698')]);
                }
                $hash = Hash::make($withPassword && $password !== null ? $password : Str::random(64));

                if ($invitation->isReset()) {
                    $user = User::query()->whereKey($invitation->user_id)->lockForUpdate()->firstOrFail();
                    // The old credentials go only now, when the new ones are set (rule 5).
                    $user->passkeys()->delete();
                    $user->recoveryCodes()->delete();
                    $user->forceFill([
                        'password' => $hash,
                        'has_password' => $withPassword,
                        'remember_token' => null,
                        'session_revoked_at' => now(),
                    ])->save();
                } else {
                    if (User::query()->where('email', $invitation->email)->exists()) {
                        throw ValidationException::withMessages(['token' => __('identity.generated.t_98562daa70f652a7')]);
                    }
                    $user = new User;
                    if ($plannedUserId !== null) {
                        $user->id = $plannedUserId;
                    }
                    $user->forceFill([
                        'name' => $name !== null && trim($name) !== '' ? trim($name) : $invitation->name,
                        'email' => $invitation->email,
                        'password' => $hash,
                        'has_password' => $withPassword,
                    ])->save();
                    $user->roles()->sync($invitation->role_ids ?? []);
                }

                $storeCredential($user);
                $invitation->delete();
                Log::info('identity.invitation.used', [
                    'invitation_id' => $invitation->id,
                    'kind' => $invitation->kind,
                    'user_id' => $user->id,
                    'credential' => $withPassword ? 'password' : 'passkey',
                ]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['token' => __('identity.generated.t_98562daa70f652a7')]);
        }

        return $user;
    }

    /** The link built from the configured public address, never from the request. */
    public function link(string $token): string
    {
        $base = rtrim((string) config('app.url'), '/');
        if ($base === '') {
            $base = rtrim(url('/'), '/');
        }

        return $base.route('identity.invitations.show', [], false).'#'.$token;
    }

    /** Pending reset links for a user disappear with the account's deactivation. */
    public function revokeResetsFor(User $user): void
    {
        UserInvitation::query()->where('kind', UserInvitation::KIND_RESET)->where('user_id', $user->id)->delete();
    }

    private function assertOpenInvitationCap(): void
    {
        $max = (int) config('identity.max_open_invitations');
        if (UserInvitation::query()->whereNull('accepted_at')->count() >= $max) {
            throw ValidationException::withMessages(['email' => __('identity.invitations.errors.invitation_cap', ['max' => $max])]);
        }
    }

    /**
     * @return Builder<UserInvitation>
     */
    private function pendingNew(): Builder
    {
        return UserInvitation::query()
            ->where('kind', UserInvitation::KIND_NEW)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now());
    }

    private function purgeExpired(): void
    {
        UserInvitation::query()->where('expires_at', '<=', now())->delete();
    }

    private function expiry(): Carbon
    {
        return now()->addMinutes((int) config('identity.invitation_ttl_minutes'));
    }

    private function audit(string $event, User $actor, UserInvitation $invitation): void
    {
        Log::info($event, [
            'actor_user_id' => $actor->id,
            'invitation_id' => $invitation->id,
            'kind' => $invitation->kind,
            'user_id' => $invitation->user_id,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);
    }
}
