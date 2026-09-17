<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AdministratorProtection
{
    /**
     * Lock active administrators so concurrent deactivations or demotions cannot remove the final administrator.
     *
     * @return Collection<int, User>
     */
    public function lockActiveAdministrators(): Collection
    {
        $administrator = Role::query()->where('key', 'administrator')->firstOrFail();

        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereKey($administrator->id))
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  array<int, string>  $roleIds
     */
    public function assertAnotherAdministratorRemains(User $targetUser, array $roleIds, bool $willBeActive): void
    {
        $administrator = Role::query()->where('key', 'administrator')->firstOrFail();
        $targetWillBeAdministrator = in_array($administrator->id, $roleIds, true);
        $activeAdministrators = $this->lockActiveAdministrators();

        $remaining = $activeAdministrators->filter(
            fn (User $user): bool => $user->id !== $targetUser->id || ($willBeActive && $targetWillBeAdministrator),
        );

        if ($remaining->isEmpty()) {
            throw ValidationException::withMessages([
                'roles' => __('identity.generated.t_693c6bffb1f2fc20'),
            ]);
        }
    }
}
