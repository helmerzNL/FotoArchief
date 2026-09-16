<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Modules\Identity\Models\UserInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class IdentityInvitationController extends Controller
{
    public function create(): View
    {
        return view('identity.invitations.create', [
            'roles' => Role::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $roleIds = Role::query()->pluck('id')->all();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:254'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', Rule::in($roleIds)],
        ]);
        $email = strtolower($data['email']);
        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'Er bestaat al een gebruiker met dit e-mailadres.']);
        }

        $token = Str::random(48);
        UserInvitation::query()->create([
            'name' => $data['name'],
            'email' => $email,
            'token_hash' => UserInvitation::hashToken($token),
            'role_ids' => array_values(array_unique($data['roles'])),
            'invited_by_user_id' => Auth::id(),
            'expires_at' => now()->addMinutes((int) config('identity.invitation_ttl_minutes')),
        ]);

        return redirect()->route('identity.users.index')->with([
            'status' => 'Uitnodiging gemaakt. Kopieer de link nu; hij wordt hierna niet meer getoond.',
            'invitation_url' => route('identity.invitations.accept', ['token' => $token]),
        ]);
    }

    public function accept(string $token): View
    {
        $invitation = $this->findAcceptableInvitation($token);

        return view('identity.invitations.accept', ['invitation' => $invitation, 'token' => $token]);
    }

    public function complete(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:14', 'max:128', 'confirmed'],
        ]);

        DB::transaction(function () use ($token, $data): void {
            $invitation = UserInvitation::query()
                ->where('token_hash', UserInvitation::hashToken($token))
                ->lockForUpdate()
                ->first();

            if (! $invitation instanceof UserInvitation || ! $invitation->isAcceptable()) {
                throw ValidationException::withMessages(['token' => 'Deze uitnodiging is ongeldig of verlopen.']);
            }
            if (User::query()->where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages(['token' => 'Deze uitnodiging kan niet meer worden gebruikt.']);
            }

            $user = User::query()->create([
                'name' => $invitation->name,
                'email' => $invitation->email,
                'password' => Hash::make($data['password']),
            ]);
            $user->roles()->sync($invitation->role_ids);
            $invitation->forceFill(['accepted_at' => now()])->save();
        });

        return redirect('/login')->with('status', 'Account geactiveerd. Je kunt nu inloggen.');
    }

    private function findAcceptableInvitation(string $token): UserInvitation
    {
        $invitation = UserInvitation::query()
            ->where('token_hash', UserInvitation::hashToken($token))
            ->first();

        if (! $invitation instanceof UserInvitation || ! $invitation->isAcceptable()) {
            abort(404);
        }

        return $invitation;
    }
}
