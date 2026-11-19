<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\Identity\Models\UserPasskey;
use App\Modules\Identity\Models\WebAuthnChallenge;
use App\Modules\Identity\Services\PasskeyData;
use App\Modules\Identity\Services\RecoveryCodeService;
use App\Modules\Identity\Services\WebAuthnCeremony;
use App\Modules\Identity\Services\WebAuthnRelyingParty;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// App-Guidance shared/guidelines/user-management.md, as adapted for FotoArchief's roles.

uses(RefreshDatabase::class);

function invitationUser(string $roleKey = 'administrator', ?string $email = null): User
{
    app(DatabaseSeeder::class)->run();
    $user = User::query()->create([
        'name' => ucfirst($roleKey).' '.str()->random(4),
        'email' => $email ?? str()->uuid().'@example.test',
        'password' => Hash::make('a-secure-test-password'),
    ]);
    $user->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

    return $user;
}

/** Makes an invitation as $admin and returns the token from the link shown once. */
function inviteToken(object $test, User $admin, string $email = 'new@example.test', string $roleKey = 'viewer'): string
{
    $role = Role::query()->where('key', $roleKey)->firstOrFail();
    $test->actingAs($admin)->post(route('identity.invitations.store'), [
        'name' => 'Nieuwe gebruiker',
        'email' => $email,
        'roles' => [$role->id],
    ])->assertRedirect(route('identity.users.index'))->assertSessionHas('invitation_url');

    return (string) str((string) session('invitation_url'))->after('#invite=');
}

function resetToken(object $test, User $admin, User $user): string
{
    $test->actingAs($admin)->post(route('identity.users.reset', $user))
        ->assertRedirect(route('identity.users.index'))->assertSessionHas('invitation_url');

    return (string) str((string) session('invitation_url'))->after('#invite=');
}

beforeEach(function (): void {
    config(['app.url' => 'https://archief.example.test']);
});

it('makes a link from the public address with the token in the fragment, shown once, stored only as a hash', function (): void {
    $admin = invitationUser();
    $token = inviteToken($this, $admin);
    $url = (string) session('invitation_url');

    expect($url)->toStartWith('https://archief.example.test/uitnodiging#invite=')
        ->and(UserInvitation::looksLikeToken($token))->toBeTrue()
        ->and(strlen($token))->toBe(43);

    $row = DB::table('user_invitations')->first();
    expect(json_encode($row))->not->toContain($token)
        ->and($row->token_hash)->toBe(hash('sha256', $token))
        ->and($row->kind)->toBe('new');

    $this->actingAs($admin)->get(route('identity.users.index'))->assertSee($url, false);
    $this->actingAs($admin)->get(route('identity.users.index'))->assertDontSee($url, false)->assertSee('new@example.test');
});

it('shows the guest only what the page needs, and the same 404 for every bad token', function (): void {
    $admin = invitationUser();
    $token = inviteToken($this, $admin);

    $this->getJson(route('identity.invitations.info'), ['X-Invite-Token' => $token])
        ->assertOk()
        ->assertJsonPath('kind', 'new')
        ->assertJsonPath('email', 'new@example.test')
        ->assertJsonPath('invitedBy', $admin->name)
        ->assertJsonMissingPath('token_hash')
        ->assertJsonMissingPath('role_ids');

    $unknown = $this->getJson(route('identity.invitations.info'), ['X-Invite-Token' => UserInvitation::newToken()])->assertNotFound()->json('message');
    $malformed = $this->getJson(route('identity.invitations.info'), ['X-Invite-Token' => '../x'])->assertNotFound()->json('message');
    $missing = $this->getJson(route('identity.invitations.info'))->assertNotFound()->json('message');
    UserInvitation::query()->update(['expires_at' => now()->subMinute()]);
    $expired = $this->getJson(route('identity.invitations.info'), ['X-Invite-Token' => $token])->assertNotFound()->json('message');

    expect([$malformed, $missing, $expired])->each->toBe($unknown);
});

it('lets the guest choose a password, signs them in, and is gone after one use', function (): void {
    $admin = invitationUser();
    $token = inviteToken($this, $admin);
    $this->flushSession();
    auth()->logout();

    $this->postJson(route('identity.invitations.password'), [
        'token' => $token,
        'name' => 'Anna de Vries',
        'password' => 'a-new-secure-password',
        'password_confirmation' => 'a-new-secure-password',
    ])->assertOk()->assertJsonPath('redirect', '/admin/assets');

    $user = User::query()->where('email', 'new@example.test')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Anna de Vries')
        ->and($user->has_password)->toBeTrue()
        ->and(Hash::check('a-new-secure-password', $user->password))->toBeTrue()
        ->and($user->roles()->where('key', 'viewer')->exists())->toBeTrue()
        ->and(UserInvitation::query()->count())->toBe(0);

    $this->postJson(route('identity.invitations.password'), [
        'token' => $token,
        'password' => 'another-secure-password',
        'password_confirmation' => 'another-secure-password',
    ])->assertUnprocessable()->assertJsonValidationErrors('token');
});

it('applies the password and name rules and creates nothing when they fail', function (): void {
    $admin = invitationUser();
    $token = inviteToken($this, $admin);

    $this->postJson(route('identity.invitations.password'), [
        'token' => $token,
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->postJson(route('identity.invitations.password'), [
        'token' => $token,
        'name' => "Anna\x07",
        'password' => 'a-new-secure-password',
        'password_confirmation' => 'a-new-secure-password',
    ])->assertUnprocessable()->assertJsonValidationErrors('name');

    expect(User::query()->where('email', 'new@example.test')->exists())->toBeFalse()
        ->and(UserInvitation::query()->count())->toBe(1);
});

it('reserves an address for a pending invitation and refuses an address that already has an account', function (): void {
    $admin = invitationUser();
    inviteToken($this, $admin);
    $role = Role::query()->where('key', 'viewer')->firstOrFail();

    $this->actingAs($admin)->post(route('identity.invitations.store'), ['name' => 'Dubbel', 'email' => 'NEW@example.test', 'roles' => [$role->id]])
        ->assertSessionHasErrors('email');
    $this->actingAs($admin)->post(route('identity.invitations.store'), ['name' => 'Bestaand', 'email' => $admin->email, 'roles' => [$role->id]])
        ->assertSessionHasErrors('email');

    expect(UserInvitation::query()->count())->toBe(1);
});

it('revokes a pending invitation so that it no longer works', function (): void {
    $admin = invitationUser();
    $token = inviteToken($this, $admin);
    $invitation = UserInvitation::query()->firstOrFail();

    $this->actingAs($admin)->delete(route('identity.invitations.revoke', $invitation))->assertRedirect(route('identity.users.index'));

    $this->getJson(route('identity.invitations.info'), ['X-Invite-Token' => $token])->assertNotFound();
    expect(UserInvitation::query()->count())->toBe(0);
});

it('lets only a user administrator make, reset and revoke links', function (): void {
    $admin = invitationUser();
    $viewer = invitationUser('viewer', 'viewer@example.test');
    inviteToken($this, $admin);
    $invitation = UserInvitation::query()->firstOrFail();
    $role = Role::query()->where('key', 'viewer')->firstOrFail();

    $this->actingAs($viewer)->post(route('identity.invitations.store'), ['name' => 'X', 'email' => 'x@example.test', 'roles' => [$role->id]])->assertForbidden();
    $this->actingAs($viewer)->post(route('identity.users.reset', $admin))->assertForbidden();
    $this->actingAs($viewer)->delete(route('identity.invitations.revoke', $invitation))->assertForbidden();
    $this->actingAs($viewer)->get(route('identity.users.index'))->assertForbidden();

    expect(UserInvitation::query()->count())->toBe(1);
});

it('purges expired invitations and holds the caps, counting pending invitations as users', function (): void {
    $admin = invitationUser();
    inviteToken($this, $admin, 'old@example.test');
    UserInvitation::query()->update(['expires_at' => now()->subMinute()]);
    inviteToken($this, $admin, 'fresh@example.test');
    expect(UserInvitation::query()->pluck('email')->all())->toBe(['fresh@example.test']);

    $role = Role::query()->where('key', 'viewer')->firstOrFail();
    config(['identity.max_open_invitations' => 1]);
    $this->actingAs($admin)->post(route('identity.invitations.store'), ['name' => 'Te veel', 'email' => 'cap@example.test', 'roles' => [$role->id]])
        ->assertSessionHasErrors('email');

    config(['identity.max_open_invitations' => 50, 'identity.max_users' => 2]);
    // One active administrator plus one pending invitation already make two.
    $this->actingAs($admin)->post(route('identity.invitations.store'), ['name' => 'Te veel', 'email' => 'cap@example.test', 'roles' => [$role->id]])
        ->assertSessionHasErrors('email');
    expect(UserInvitation::query()->count())->toBe(1);
});

it('resets a login only when the new one is set, removing the old password, passkeys and recovery codes and signing old sessions out', function (): void {
    $admin = invitationUser();
    $user = invitationUser('viewer', 'locked@example.test');
    UserPasskey::query()->create(['user_id' => $user->id, 'name' => 'Oude telefoon', 'credential_id' => base64_encode('old'), 'credential_public_key' => 'key', 'signature_counter' => 0]);
    app(RecoveryCodeService::class)->regenerate($user);

    $token = resetToken($this, $admin, $user);
    $this->getJson(route('identity.invitations.info'), ['X-Invite-Token' => $token])->assertOk()->assertJsonPath('kind', 'reset')->assertJsonPath('email', 'locked@example.test');

    // Nothing changed yet: the old password, passkey and codes still work.
    $user->refresh();
    expect(Hash::check('a-secure-test-password', $user->password))->toBeTrue()
        ->and($user->passkeys()->count())->toBe(1)
        ->and($user->recoveryCodes()->count())->toBeGreaterThan(0)
        ->and($user->session_revoked_at)->toBeNull();

    $this->flushSession();
    auth()->logout();
    $this->postJson(route('identity.invitations.password'), [
        'token' => $token,
        'name' => 'Een andere naam',
        'password' => 'the-replacement-password',
        'password_confirmation' => 'the-replacement-password',
    ])->assertOk();

    $user->refresh();
    expect($user->name)->not->toBe('Een andere naam')
        ->and(Hash::check('the-replacement-password', $user->password))->toBeTrue()
        ->and(Hash::check('a-secure-test-password', $user->password))->toBeFalse()
        ->and($user->passkeys()->count())->toBe(0)
        ->and($user->recoveryCodes()->count())->toBe(0)
        ->and($user->session_revoked_at)->not->toBeNull()
        ->and(UserInvitation::query()->count())->toBe(0);

    // A session from before the reset is signed out on its next request.
    $this->flushSession();
    $this->actingAs($user)->get(route('admin.assets.index'))->assertRedirect('/login');
});

it('keeps one reset link per user, refuses your own and an inactive account, and drops it on deactivation', function (): void {
    $admin = invitationUser();
    $user = invitationUser('viewer', 'locked@example.test');

    $first = resetToken($this, $admin, $user);
    $second = resetToken($this, $admin, $user);
    expect(UserInvitation::query()->where('kind', 'reset')->count())->toBe(1);
    $this->getJson(route('identity.invitations.info'), ['X-Invite-Token' => $first])->assertNotFound();
    $this->getJson(route('identity.invitations.info'), ['X-Invite-Token' => $second])->assertOk();

    $this->actingAs($admin)->post(route('identity.users.reset', $admin))->assertSessionHasErrors('user');

    $this->actingAs($admin)->post(route('identity.users.deactivate', $user))->assertRedirect(route('identity.users.index'));
    $this->getJson(route('identity.invitations.info'), ['X-Invite-Token' => $second])->assertNotFound();
    $this->actingAs($admin)->post(route('identity.users.reset', $user->refresh()))->assertSessionHasErrors('user');
    expect(UserInvitation::query()->count())->toBe(0);
});

it('lets the guest choose a passkey instead, through the same ceremony and the same account rules', function (): void {
    config(['app.url' => 'http://localhost']);
    $admin = invitationUser();
    $token = inviteToken($this, $admin, 'passkey@example.test');
    $fake = new class(app(WebAuthnRelyingParty::class)) extends WebAuthnCeremony
    {
        public function verifyEnrollment(WebAuthnChallenge $challenge, array $payload): PasskeyData
        {
            return new PasskeyData(base64_encode('new-credential'), '-----BEGIN PUBLIC KEY----- test', 0, ['internal']);
        }
    };
    $this->app->instance(WebAuthnCeremony::class, $fake);
    $this->flushSession();
    auth()->logout();

    $this->postJson(route('identity.invitations.passkey.options'), ['token' => $token, 'name' => 'Piet'])
        ->assertOk()
        ->assertJsonPath('publicKey.user.name', 'passkey@example.test');
    $pending = session('identity.invitation');
    expect($pending)->toBeArray();

    $this->withSession(['identity.invitation' => $pending])->postJson(route('identity.invitations.passkey.store'), [
        'token' => $token,
        'name' => 'Piet',
        'passkeyName' => 'Telefoon',
        'clientDataJSON' => base64_encode('client'),
        'attestationObject' => base64_encode('attestation'),
    ])->assertOk();

    $user = User::query()->where('email', 'passkey@example.test')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->id)->toBe($pending['user_id'])
        ->and($user->name)->toBe('Piet')
        ->and($user->has_password)->toBeFalse()
        ->and($user->passkeys()->pluck('name')->all())->toBe(['Telefoon'])
        ->and(UserInvitation::query()->count())->toBe(0);
});

it('gives no passkey options for a bad invitation or an address that has an account meanwhile', function (): void {
    config(['app.url' => 'http://localhost']);
    $admin = invitationUser();
    $token = inviteToken($this, $admin, 'taken@example.test');

    $this->postJson(route('identity.invitations.passkey.options'), ['token' => UserInvitation::newToken()])->assertNotFound();
    invitationUser('viewer', 'taken@example.test');
    $this->postJson(route('identity.invitations.passkey.options'), ['token' => $token])->assertUnprocessable();
    expect(WebAuthnChallenge::query()->where('purpose', 'invitation')->count())->toBe(0);
});

it('sends an old path link to the page with the token in the fragment', function (): void {
    $this->get('/uitnodigingen/'.str_repeat('a', 48))->assertRedirect('/uitnodiging#invite='.str_repeat('a', 48));
});

it('lists how each user signs in and the open invitations', function (): void {
    $admin = invitationUser();
    $user = invitationUser('viewer', 'listed@example.test');
    UserPasskey::query()->create(['user_id' => $user->id, 'name' => 'Laptop', 'credential_id' => base64_encode('c'), 'credential_public_key' => 'key', 'signature_counter' => 0]);
    resetToken($this, $admin, $user);

    $this->actingAs($admin)->get(route('identity.users.index'))
        ->assertOk()
        ->assertSee(__('identity.users.method_password'))
        ->assertSee(trans_choice('identity.users.method_passkeys', 1, ['count' => 1]))
        ->assertSee(__('identity.users.kind_reset'))
        ->assertSee(__('identity.users.revoke'));
});
