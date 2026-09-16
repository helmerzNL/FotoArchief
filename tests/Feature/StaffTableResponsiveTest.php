<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Bug report: /admin/suggesties overflowed the document width at a 390px
 * viewport (document width measured at 455px). Both staff tables now render
 * inside a bounded, keyboard-focusable x-table-scroll wrapper
 * (resources/views/components/table-scroll.blade.php) instead of an
 * unwrapped <table>, so the region scrolls horizontally on its own and the
 * page itself no longer widens. This is a Portal-local component, not a
 * dependency on Catalogue's x-catalogue-table.
 */
uses(RefreshDatabase::class);

function staffTableResponsiveUser(string $role = 'administrator'): User
{
    $user = User::query()->create(['name' => $role, 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

    return $user;
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('wraps the publications staff table in a bounded, keyboard-focusable scroll region', function (): void {
    $response = $this->actingAs(staffTableResponsiveUser())->get('/admin/publications');

    $response->assertOk()
        ->assertSee('class="table-scroll"', false)
        ->assertSee('role="region"', false)
        ->assertSee('tabindex="0"', false);
});

it('wraps the suggestions staff table in a bounded, keyboard-focusable scroll region', function (): void {
    $response = $this->actingAs(staffTableResponsiveUser())->get('/admin/suggesties');

    $response->assertOk()
        ->assertSee('class="table-scroll"', false)
        ->assertSee('role="region"', false)
        ->assertSee('tabindex="0"', false);
});
