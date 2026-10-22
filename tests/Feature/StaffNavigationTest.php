<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function navigationUser(string $role): User
{
    $user = User::query()->create(['name' => 'Nav '.$role, 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

    return $user;
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('offers the workspace in four destinations instead of a flat list of links', function (): void {
    $html = $this->actingAs(navigationUser('administrator'))->get('/admin/assets')->assertOk()->getContent();

    foreach (['Foto’s', 'Catalogus', 'Publiceren', 'Beheer'] as $destination) {
        expect(substr_count($html, 'nav-label">'.$destination.'<'))->toBe(2); // sidebar and bottom bar
    }
    // One navigator at a time is shown by CSS; both carry the same accessible name.
    expect(substr_count($html, 'aria-label="Beheer"'))->toBe(2)
        ->and(substr_count($html, 'aria-current="page"'))->toBeGreaterThanOrEqual(3);
});

it('marks the active destination and lists its pages as section tabs', function (): void {
    $response = $this->actingAs(navigationUser('administrator'))->get('/admin/catalogue/collections')->assertOk();

    $response->assertSee('title="Catalogus" aria-current="page"', false)
        ->assertSee('aria-label="Onderdelen van Catalogus"', false)
        ->assertSee('<a href="'.route('catalogue.collections.index').'" aria-current="page">Collecties</a>', false)
        ->assertSee('<a href="'.route('catalogue.people.index').'">Personen</a>', false);
});

it('puts notifications, exchange and users under Beheer and keeps the most specific tab active', function (): void {
    $response = $this->actingAs(navigationUser('administrator'))->get('/admin/operations/notifications')->assertOk();

    $response->assertSee('title="Beheer" aria-current="page"', false)
        ->assertSee('<a href="'.route('admin.operations.notifications').'" aria-current="page">Mijn meldingen</a>', false)
        ->assertSee('<a href="'.route('admin.operations.index').'">Archiefbewerkingen</a>', false)
        ->assertSee('<a href="'.route('exchange.index').'">Uitwisseling</a>', false)
        ->assertSee('<a href="'.route('identity.users.index').'">Gebruikers</a>', false);
});

it('hides user management from roles that may not manage users', function (): void {
    $response = $this->actingAs(navigationUser('editor'))->get('/admin/assets')->assertOk();

    $response->assertDontSee('>Gebruikers<', false)->assertDontSee('>Overzicht</a>', false);
    // The security page stays reachable from the account menu for everyone.
    $response->assertSee(route('identity.security.show'), false);
});

it('keeps appearance, accent colour and sign-out in the account menu', function (): void {
    $response = $this->actingAs(navigationUser('administrator'))->get('/admin/assets')->assertOk();

    $response->assertSee('id="appearance-menu"', false)
        ->assertSee('data-accent-choice="groen" aria-pressed="true"', false)
        ->assertSee('Archiefgroen')->assertSee('Terracotta')->assertSee('Pruim')
        ->assertSee('id="sidebar-toggle"', false)
        ->assertSee('Uitloggen');
});

it('shows signed-in staff a way back to the workspace on public pages instead of the staff navigation', function (): void {
    $response = $this->actingAs(navigationUser('administrator'))->get('/ontdek')->assertOk();

    $response->assertSee('Naar beheer')->assertDontSee('class="staff-nav"', false)->assertDontSee('class="bottom-nav', false);
});
