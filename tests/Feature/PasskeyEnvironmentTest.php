<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/*
 * Behind a reverse proxy the app may not know the public scheme or host. Absolute URLs
 * in the passkey script then point elsewhere and the browser reports only "Failed to
 * fetch". The pages use same-origin paths and name the real cause up front.
 */

function passkeyPageUser(): User
{
    app(DatabaseSeeder::class)->run();
    $user = User::query()->create(['name' => 'Beheer', 'email' => str()->uuid().'@example.test', 'password' => Hash::make('a-secure-test-password')]);
    $user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());

    return $user;
}

it('calls the passkey endpoints with same-origin paths and passes the APP_URL origin to the check', function (): void {
    config(['app.url' => 'https://foto.example.nl']);

    $this->actingAs(passkeyPageUser())->get(route('identity.security.show'))
        ->assertOk()
        ->assertSee(json_encode(route('identity.passkeys.options', [], false), JSON_THROW_ON_ERROR), false)
        ->assertSee(json_encode(route('identity.passkeys.store', [], false), JSON_THROW_ON_ERROR), false)
        ->assertDontSee(json_encode(route('identity.passkeys.options'), JSON_THROW_ON_ERROR), false)
        ->assertSee(json_encode('https://foto.example.nl', JSON_THROW_ON_ERROR), false)
        ->assertSee('Passkeys werken alleen op :configured', false)
        ->assertDontSee('data-passkey-problem', false);
});

it('uses same-origin paths for passkey login as well', function (): void {
    config(['app.url' => 'https://foto.example.nl']);

    $this->get('/login')
        ->assertOk()
        ->assertSee(json_encode(route('identity.passkeys.login.options', [], false), JSON_THROW_ON_ERROR), false)
        ->assertSee(json_encode(route('identity.passkeys.login', [], false), JSON_THROW_ON_ERROR), false)
        ->assertSee('TRUSTED_PROXIES', false);
});

it('explains on the page when APP_URL cannot carry passkeys', function (): void {
    config(['app.url' => 'http://192.168.1.10:8080']);

    $this->actingAs(passkeyPageUser())->get(route('identity.security.show'))
        ->assertOk()
        ->assertSee('data-passkey-problem', false)
        ->assertSee('APP_URL must be a valid HTTPS origin', false);
});
