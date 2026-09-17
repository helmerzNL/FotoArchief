<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Identity\Models\UserPasskey;
use App\Modules\Identity\Models\UserRecoveryCode;
use App\Modules\Identity\Models\WebAuthnChallenge;
use App\Modules\Identity\Services\PasskeyData;
use App\Modules\Identity\Services\WebAuthnCeremony;
use App\Modules\Identity\Services\WebAuthnChallengeRepository;
use App\Modules\Identity\Services\WebAuthnRelyingParty;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function identitySecurityUser(string $roleKey = 'administrator', ?string $email = null): User
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

it('derives passkey enrollment options and a short single-use challenge from APP_URL', function (): void {
    config(['app.url' => 'http://localhost:8080']);
    $user = identitySecurityUser();

    $response = $this->actingAs($user)->postJson(route('identity.passkeys.options'));

    $response->assertOk()
        ->assertJsonPath('publicKey.rp.id', 'localhost')
        ->assertJsonPath('publicKey.authenticatorSelection.residentKey', 'required');
    $challenge = WebAuthnChallenge::query()->firstOrFail();
    expect($challenge->purpose)->toBe('enrollment')
        ->and($challenge->user_id)->toBe($user->id)
        ->and($challenge->expires_at->diffInMinutes(now()))->toBeLessThanOrEqual(5);
});

it('stores a passkey only after consuming the matching enrollment challenge', function (): void {
    $user = identitySecurityUser();
    $challenge = app(WebAuthnChallengeRepository::class)->store('enrollment', 'binary-challenge', $user);
    $fake = new class(app(WebAuthnRelyingParty::class)) extends WebAuthnCeremony
    {
        public function verifyEnrollment(WebAuthnChallenge $challenge, array $payload): PasskeyData
        {
            return new PasskeyData(base64_encode('credential-id'), '-----BEGIN PUBLIC KEY----- test', 1, ['internal']);
        }
    };
    $this->app->instance(WebAuthnCeremony::class, $fake);

    $this->actingAs($user)->withSession(['identity.enrollment_challenge_id' => $challenge->id])->postJson(route('identity.passkeys.store'), [
        'name' => 'Laptop',
        'clientDataJSON' => base64_encode('client'),
        'attestationObject' => base64_encode('attestation'),
        'transports' => ['internal'],
    ])->assertCreated();

    expect(UserPasskey::query()->where('user_id', $user->id)->where('name', 'Laptop')->exists())->toBeTrue()
        ->and($challenge->refresh()->consumed_at)->not->toBeNull();

    $this->actingAs($user)->withSession(['identity.enrollment_challenge_id' => $challenge->id])->postJson(route('identity.passkeys.store'), [
        'name' => 'Laptop opnieuw',
        'clientDataJSON' => base64_encode('client'),
        'attestationObject' => base64_encode('attestation'),
    ])->assertUnprocessable();
});

it('starts passkey login without asking for an email address', function (): void {
    config(['app.url' => 'http://localhost']);

    $response = $this->postJson(route('identity.passkeys.login.options'));

    $response->assertOk()
        ->assertJsonPath('publicKey.rpId', 'localhost')
        ->assertJsonMissingPath('publicKey.allowCredentials');
    expect(WebAuthnChallenge::query()->where('purpose', 'login')->count())->toBe(1);
});

it('derives passkey origin from the configured public HTTPS origin and rejects mismatches', function (): void {
    config(['app.url' => 'https://archief.example.org:8443']);
    $party = app(WebAuthnRelyingParty::class);

    expect($party->rpId())->toBe('archief.example.org')
        ->and($party->origin())->toBe('https://archief.example.org:8443');

    $party->assertClientDataOrigin(json_encode(['origin' => 'https://archief.example.org:8443'], JSON_THROW_ON_ERROR));

    expect(fn () => $party->assertClientDataOrigin(json_encode(['origin' => 'https://evil.example.org'], JSON_THROW_ON_ERROR)))
        ->toThrow(InvalidArgumentException::class, 'WebAuthn origin does not match');
});

it('rejects malformed client data instead of accepting a missing or invalid origin', function (string $clientData): void {
    config(['app.url' => 'https://archief.example.org']);
    $party = app(WebAuthnRelyingParty::class);

    expect(fn () => $party->assertClientDataOrigin($clientData))
        ->toThrow(InvalidArgumentException::class, 'WebAuthn origin does not match');
})->with([
    'invalid JSON' => ['not-json'],
    'missing origin' => [json_encode(['type' => 'webauthn.get'], JSON_THROW_ON_ERROR)],
    'wrong scheme' => [json_encode(['origin' => 'http://archief.example.org'], JSON_THROW_ON_ERROR)],
]);

it('refuses non-local insecure passkey origins', function (): void {
    config(['app.url' => 'http://archief.example.org']);

    expect(fn () => app(WebAuthnRelyingParty::class)->origin())
        ->toThrow(RuntimeException::class, 'valid HTTPS origin');
});

it('marks session cookies secure when production HTTPS is configured', function (): void {
    config(['session.secure' => true]);
    $user = identitySecurityUser('viewer', 'viewer@example.test');

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'a-secure-test-password',
    ]);

    expect(strtolower((string) $response->headers->getCookies()[0]))->toContain('secure');
});

it('logs in with a verified discoverable passkey without account enumeration', function (): void {
    $user = identitySecurityUser('viewer', 'viewer@example.test');
    $passkey = UserPasskey::query()->create([
        'user_id' => $user->id,
        'name' => 'Telefoon',
        'credential_id' => base64_encode('credential-id'),
        'credential_public_key' => '-----BEGIN PUBLIC KEY----- test',
        'signature_counter' => 1,
    ]);
    $challenge = app(WebAuthnChallengeRepository::class)->store('login', 'login-challenge', null);
    $fake = new class(app(WebAuthnRelyingParty::class)) extends WebAuthnCeremony
    {
        public function verifyLogin(UserPasskey $passkey, WebAuthnChallenge $challenge, array $payload): void {}

        public function signatureCounter(): ?int
        {
            return 2;
        }
    };
    $this->app->instance(WebAuthnCeremony::class, $fake);

    $this->withSession(['identity.login_challenge_id' => $challenge->id])->postJson(route('identity.passkeys.login'), [
        'id' => base64_encode('credential-id'),
        'clientDataJSON' => base64_encode('client'),
        'authenticatorData' => base64_encode('authenticator'),
        'signature' => base64_encode('signature'),
        'userHandle' => base64_encode($user->id),
    ])->assertOk()->assertJsonPath('redirect', '/admin/assets');

    $this->assertAuthenticatedAs($user);
    expect($passkey->refresh()->signature_counter)->toBe(2)
        ->and($challenge->refresh()->consumed_at)->not->toBeNull();

    Auth::logout();
    $secondChallenge = app(WebAuthnChallengeRepository::class)->store('login', 'second-login-challenge', null);
    $this->withSession(['identity.login_challenge_id' => $secondChallenge->id])->postJson(route('identity.passkeys.login'), [
        'id' => base64_encode('unknown-credential'),
        'clientDataJSON' => base64_encode('client'),
        'authenticatorData' => base64_encode('authenticator'),
        'signature' => base64_encode('signature'),
        'userHandle' => base64_encode($user->id),
    ])->assertUnprocessable()->assertJsonValidationErrors('passkey');
});

it('generates hashed one-use recovery codes and logs in with one code once', function (): void {
    $user = identitySecurityUser('viewer', 'viewer@example.test');

    $this->actingAs($user)->post(route('identity.recovery.regenerate'))->assertRedirect(route('identity.security.show'))->assertSessionHas('recovery_codes');
    $codes = session('recovery_codes');
    expect($codes)->toHaveCount(10)
        ->and(UserRecoveryCode::query()->where('user_id', $user->id)->count())->toBe(10)
        ->and(UserRecoveryCode::query()->where('code_hash', $codes[0])->exists())->toBeFalse();

    Auth::logout();
    $this->post(route('identity.recovery.login'), [
        'email' => 'viewer@example.test',
        'code' => $codes[0],
    ])->assertRedirect('/admin/assets');
    $this->assertAuthenticatedAs($user);

    Auth::logout();
    $this->post(route('identity.recovery.login'), [
        'email' => 'viewer@example.test',
        'code' => $codes[0],
    ])->assertSessionHasErrors('email');
    expect(UserRecoveryCode::query()->whereNotNull('used_at')->count())->toBe(1);
});
