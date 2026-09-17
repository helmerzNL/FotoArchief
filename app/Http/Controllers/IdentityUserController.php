<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Modules\Identity\Services\AdministratorProtection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class IdentityUserController extends Controller
{
    public function index(): View
    {
        return view('identity.users.index', [
            'users' => User::query()->with('roles')->orderBy('name')->get(),
            'roles' => Role::query()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user, AdministratorProtection $protection): RedirectResponse
    {
        $roleIds = Role::query()->pluck('id')->all();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', Rule::in($roleIds)],
        ]);

        DB::transaction(function () use ($user, $data, $protection): void {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $newRoleIds = array_values(array_unique($data['roles']));
            $protection->assertAnotherAdministratorRemains($lockedUser, $newRoleIds, $lockedUser->is_active);
            $lockedUser->forceFill(['name' => $data['name']])->save();
            $lockedUser->roles()->sync($newRoleIds);
            $lockedUser->forceFill(['session_revoked_at' => now()])->save();
        });

        return redirect()->route('identity.users.index')->with('status', __('identity.generated.t_e428b4f28d705346'));
    }

    public function deactivate(User $user, AdministratorProtection $protection): RedirectResponse
    {
        DB::transaction(function () use ($user, $protection): void {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $lockedUser->is_active) {
                return;
            }
            $roleIds = $lockedUser->roles()->pluck('roles.id')->all();
            $protection->assertAnotherAdministratorRemains($lockedUser, $roleIds, false);
            $lockedUser->forceFill([
                'is_active' => false,
                'deactivated_at' => now(),
                'session_revoked_at' => now(),
                'remember_token' => null,
            ])->save();
        });

        return redirect()->route('identity.users.index')->with('status', __('identity.generated.t_dea2c3ee98eddc77'));
    }

    public function reactivate(User $user): RedirectResponse
    {
        $user->forceFill([
            'is_active' => true,
            'deactivated_at' => null,
            'session_revoked_at' => now(),
        ])->save();

        return redirect()->route('identity.users.index')->with('status', __('identity.generated.t_6b98fa9d4a9279d4'));
    }
}
