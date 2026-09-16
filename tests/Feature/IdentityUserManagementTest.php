<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Identity\Models\UserInvitation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function identityUser(string $roleKey = 'administrator', ?string $email = null): User
{
    app(DatabaseSeeder::class)->run();
    $user = User::query()->create([
        'name' => ucfirst($roleKey),
        'email' => $email ?? str()->uuid().'@example.test',
        'password' => Hash::make('a-secure-test-password'),
    ]);
    $user->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

    return $user;
}

it('creates a single-use invitation link visible only after creation', function (): void {
    $admin = identityUser();
    $role = Role::query()->where('key', 'viewer')->firstOrFail();

    $response = $this->actingAs($admin)->post(route('identity.invitations.store'), [
        'name' => 'Nieuwe gebruiker',
        'email' => 'new@example.test',
        'roles' => [$role->id],
    ]);

    $response->assertRedirect(route('identity.users.index'))->assertSessionHas('invitation_url');
    $url = session('invitation_url');
    expect(UserInvitation::query()->where('email', 'new@example.test')->count())->toBe(1)
        ->and($url)->toContain('/uitnodigingen/');

    $this->actingAs($admin)->get(route('identity.users.index'))->assertSee($url);
    $this->actingAs($admin)->get(route('identity.users.index'))->assertDontSee($url);
});

it('accepts an invitation once and assigns the selected role', function (): void {
    $admin = identityUser();
    $role = Role::query()->where('key', 'viewer')->firstOrFail();
    $this->actingAs($admin)->post(route('identity.invitations.store'), [
        'name' => 'Viewer',
        'email' => 'viewer@example.test',
        'roles' => [$role->id],
    ]);
    $token = basename((string) session('invitation_url'));

    $this->post(route('identity.invitations.complete', ['token' => $token]), [
        'password' => 'a-new-secure-password',
        'password_confirmation' => 'a-new-secure-password',
    ])->assertRedirect('/login');

    $user = User::query()->where('email', 'viewer@example.test')->firstOrFail();
    expect($user->roles()->where('key', 'viewer')->exists())->toBeTrue()
        ->and(Hash::check('a-new-secure-password', $user->password))->toBeTrue();

    $this->post(route('identity.invitations.complete', ['token' => $token]), [
        'password' => 'a-new-secure-password',
        'password_confirmation' => 'a-new-secure-password',
    ])->assertSessionHasErrors('token');
});

it('manages roles and revokes existing sessions', function (): void {
    $admin = identityUser();
    $user = identityUser('viewer', 'viewer@example.test');
    $editor = Role::query()->where('key', 'editor')->firstOrFail();

    $this->actingAs($admin)->put(route('identity.users.update', $user), [
        'name' => 'Editor User',
        'roles' => [$editor->id],
    ])->assertRedirect(route('identity.users.index'));

    $user->refresh();
    expect($user->name)->toBe('Editor User')
        ->and($user->roles()->where('key', 'editor')->exists())->toBeTrue()
        ->and($user->session_revoked_at)->not->toBeNull();
});

it('deactivates users and denies their next authenticated request', function (): void {
    $admin = identityUser();
    $user = identityUser('viewer', 'viewer@example.test');
    $this->actingAs($admin)->post(route('identity.users.deactivate', $user))->assertRedirect(route('identity.users.index'));

    $this->actingAs($user->refresh())->get(route('admin.assets.index'))->assertRedirect('/login');
    expect($user->refresh()->is_active)->toBeFalse();
});

it('protects the final active administrator from deactivation and demotion', function (): void {
    $admin = identityUser();
    $viewer = Role::query()->where('key', 'viewer')->firstOrFail();

    $this->actingAs($admin)->post(route('identity.users.deactivate', $admin))->assertSessionHasErrors('roles');
    $this->actingAs($admin)->put(route('identity.users.update', $admin), [
        'name' => $admin->name,
        'roles' => [$viewer->id],
    ])->assertSessionHasErrors('roles');

    expect($admin->refresh()->is_active)->toBeTrue()
        ->and($admin->roles()->where('key', 'administrator')->exists())->toBeTrue();
});
