<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function discoveryUser(string $email, array $permissions): User
{
    $role = Role::query()->firstOrCreate(['key' => 'discovery-'.md5(implode(',', $permissions))], ['name' => 'Discovery']);
    foreach ($permissions as $key) {
        $permission = Permission::query()->firstOrCreate(['key' => $key], ['name' => $key]);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }

    $user = User::query()->create([
        'name' => 'Ontdekker',
        'email' => $email,
        'password' => Hash::make('disposable-discovery-password'),
        'email_verified_at' => now(),
    ]);
    $user->roles()->attach($role);

    return $user;
}

it('offers an archivist without users.manage a way into operations from the header', function (): void {
    $archivist = discoveryUser('archivaris@example.test', ['assets.view', 'assets.update', 'catalogue.manage']);

    $response = $this->actingAs($archivist)->get(route('admin.assets.index'));

    $response->assertOk()
        ->assertSee(route('admin.operations.index'), false)
        // Diagnostics needs users.manage, so linking there would have answered 403.
        ->assertDontSee(route('admin.operations.diagnostics'), false);
});

it('shows the landing page with only the operations the user may open', function (): void {
    $archivist = discoveryUser('archivaris2@example.test', ['assets.view', 'assets.update', 'catalogue.manage']);

    $response = $this->actingAs($archivist)->get(route('admin.operations.index'));

    $response->assertOk()
        ->assertSee('Archiefbewerkingen')
        ->assertSee(route('admin.operations.duplicates.index'), false)
        ->assertSee(route('admin.operations.ocr.index'), false)
        ->assertDontSee(route('admin.operations.diagnostics'), false);
});

it('lets an administrator reach diagnostics from the same landing', function (): void {
    $admin = discoveryUser('beheerder@example.test', ['users.manage', 'assets.view']);

    $this->actingAs($admin)
        ->get(route('admin.operations.index'))
        ->assertOk()
        ->assertSee(route('admin.operations.diagnostics'), false);
});

it('denies the landing to a signed-in user with no operations permission at all', function (): void {
    $outsider = discoveryUser('buitenstaander@example.test', ['profile.view']);

    $this->actingAs($outsider)->get(route('admin.operations.index'))->assertForbidden();
});

it('fails the ocr smoke command with a clear reason when the binary cannot run', function (): void {
    config()->set('services.tesseract.enabled', true);
    config()->set('services.tesseract.binary', 'tesseract-does-not-exist');

    $this->artisan('operations:ocr-smoke')
        ->expectsOutputToContain('Tesseract is niet beschikbaar')
        ->assertExitCode(1);
});

it('fails the ocr smoke command when OCR is switched off rather than pretending it works', function (): void {
    config()->set('services.tesseract.enabled', false);

    $this->artisan('operations:ocr-smoke')->assertExitCode(1);
});
