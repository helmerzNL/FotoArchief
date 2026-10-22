<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function preferenceUser(): User
{
    $user = User::query()->create(['name' => 'Voorkeur', 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());

    return $user;
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('saves valid choices on the account and merges partial updates', function (): void {
    $user = preferenceUser();
    $this->actingAs($user);

    $this->putJson(route('identity.preferences.update'), ['accent' => 'terracotta'])
        ->assertOk()->assertExactJson(['accent' => 'terracotta']);
    $this->putJson(route('identity.preferences.update'), ['theme' => 'dark', 'sidebar' => 'collapsed'])
        ->assertOk()->assertExactJson(['theme' => 'dark', 'accent' => 'terracotta', 'sidebar' => 'collapsed']);

    expect($user->fresh()->interface_preferences)->toBe(['theme' => 'dark', 'accent' => 'terracotta', 'sidebar' => 'collapsed']);
});

it('rejects unknown values and ignores unknown keys without touching what is stored', function (): void {
    $user = preferenceUser();
    $this->actingAs($user)->putJson(route('identity.preferences.update'), ['accent' => 'blauw'])->assertOk();

    $this->putJson(route('identity.preferences.update'), ['accent' => 'neon'])->assertUnprocessable()->assertJsonValidationErrors('accent');
    $this->putJson(route('identity.preferences.update'), ['theme' => ['dark']])->assertUnprocessable();
    $this->putJson(route('identity.preferences.update'), ['role' => 'administrator'])->assertOk()->assertExactJson(['accent' => 'blauw']);

    expect($user->fresh()->interface_preferences)->toBe(['accent' => 'blauw']);
});

it('keeps each person’s choices separate and needs a signed-in user', function (): void {
    $first = preferenceUser();
    $second = preferenceUser();
    $this->actingAs($first)->putJson(route('identity.preferences.update'), ['theme' => 'light'])->assertOk();

    expect($second->fresh()->interface_preferences)->toBeNull();
});

it('refuses visitors without an account', function (): void {
    $this->putJson(route('identity.preferences.update'), ['theme' => 'dark'])->assertUnauthorized();
});

it('renders the account choices into the page for signed-in staff', function (): void {
    $user = preferenceUser();
    $this->actingAs($user)->get('/admin/assets')->assertOk()
        ->assertSee('data-user-prefs="{}"', false)
        ->assertSee('data-prefs-url="'.route('identity.preferences.update').'"', false);

    $this->putJson(route('identity.preferences.update'), ['theme' => 'dark', 'accent' => 'pruim'])->assertOk();
    $this->get('/ontdek')->assertOk()
        ->assertSee('data-user-prefs="{&quot;theme&quot;:&quot;dark&quot;,&quot;accent&quot;:&quot;pruim&quot;}"', false);
});

it('keeps account preferences out of pages for visitors', function (): void {
    $this->get('/ontdek')->assertOk()->assertDontSee('data-user-prefs', false)->assertDontSee('data-prefs-url', false);
});

it('never lets a corrupt stored value reach the page', function (): void {
    $user = preferenceUser();
    $user->forceFill(['interface_preferences' => ['accent' => 'neon', 'theme' => 'dark', 'x' => 1, 'sidebar' => ['collapsed']]])->save();

    $this->actingAs($user)->get('/admin/assets')->assertOk()
        ->assertSee('data-user-prefs="{&quot;theme&quot;:&quot;dark&quot;}"', false)
        ->assertDontSee('neon');
});
