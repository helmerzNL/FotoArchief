<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createPersonTestUser(string $roleKey, array $permissions): User
{
    $role = Role::query()->firstOrCreate(['key' => $roleKey], ['name' => ucfirst($roleKey)]);
    $permissionModels = collect($permissions)->map(function ($key) {
        return Permission::query()->firstOrCreate(['key' => $key], ['name' => $key]);
    });
    $role->permissions()->sync($permissionModels->pluck('id'));

    $user = User::query()->create([
        'name' => 'User '.$roleKey,
        'email' => $roleKey.'@example.test',
        'password' => 'password123',
    ]);
    $user->roles()->attach($role);

    return $user;
}

it('creates person and organisation with aliases and allows alias search', function (): void {
    $admin = createPersonTestUser('admin', ['assets.view', 'catalogue.manage', 'assets.update']);

    // Create Person with aliases
    $this->actingAs($admin)->post(route('catalogue.people.store'), [
        'entity_type' => 'person',
        'display_name' => 'Cornelis van Dijk',
        'sort_name' => 'Dijk, Cornelis van',
        'birth_date_precision' => 'year',
        'birth_date_earliest' => '1890-01-01',
        'death_date_precision' => 'circa',
        'death_date_earliest' => '1965-01-01',
        'biographical_note' => 'Lokale bakker en raadslid.',
        'aliases' => 'Kees van Dijk, Cees Dijk',
    ])->assertSessionHasNoErrors();

    $person = Person::query()->where('display_name', 'Cornelis van Dijk')->firstOrFail();
    expect($person->entity_type)->toBe('person')
        ->and($person->aliases()->count())->toBe(2);

    // Create Organisation
    $this->actingAs($admin)->post(route('catalogue.people.store'), [
        'entity_type' => 'organisation',
        'display_name' => 'Harmonie Sint Cecilia',
        'sort_name' => 'Harmonie Sint Cecilia',
        'birth_date_precision' => 'year',
        'birth_date_earliest' => '1905-01-01',
        'death_date_precision' => 'unknown',
        'aliases' => 'Muziekvereniging Cecilia',
    ])->assertSessionHasNoErrors();

    // Search by alias
    $this->actingAs($admin)
        ->get(route('catalogue.people.index', ['q' => 'Kees van Dijk']))
        ->assertOk()
        ->assertSee('Cornelis van Dijk');

    // Filter by entity_type
    $this->actingAs($admin)
        ->get(route('catalogue.people.index', ['entity_type' => 'organisation']))
        ->assertOk()
        ->assertSee('Harmonie Sint Cecilia')
        ->assertDontSee('Cornelis van Dijk');
});

it('associates photo with role, uncertainty, verification status, and enforces permission guards', function (): void {
    $admin = createPersonTestUser('admin', ['assets.view', 'catalogue.manage', 'assets.update', 'assets.publish']);
    $viewer = createPersonTestUser('viewer', ['assets.view']);
    $otherUser = createPersonTestUser('volunteer', ['assets.view', 'assets.update']);

    $person = Person::query()->create([
        'entity_type' => 'person',
        'display_name' => 'Anna Bakker',
        'sort_name' => 'Bakker, Anna',
    ]);

    $asset = Asset::query()->create([
        'accession_number' => 'FA-PERSON-01',
        'title' => 'Portret in studio',
        'created_by_user_id' => $admin->id,
    ]);

    $privateAssetOther = Asset::query()->create([
        'accession_number' => 'FA-PRIV-02',
        'title' => 'Privé foto',
        'created_by_user_id' => $otherUser->id,
    ]);

    // Attach asset with photographer role and uncertainty
    $this->actingAs($admin)->post(route('catalogue.people.assets.add', $person), [
        'accession_number' => 'FA-PERSON-01',
        'relationship_type' => 'photographer',
        'confidence' => '0.80',
        'verification_status' => 'verified',
        'note' => 'Stempel op achterzijde',
    ])->assertSessionHasNoErrors();

    $attached = $person->assets()->first();
    expect($attached)->not->toBeNull()
        ->and($attached->pivot->relationship_type)->toBe('photographer')
        ->and((float) $attached->pivot->confidence)->toBe(0.8)
        ->and($attached->pivot->verification_status)->toBe('verified');

    // Viewer cannot manage or attach
    $this->actingAs($viewer)->post(route('catalogue.people.assets.add', $person), [
        'accession_number' => 'FA-PERSON-01',
        'relationship_type' => 'depicted',
        'verification_status' => 'unverified',
    ])->assertForbidden();

    // User cannot attach private asset belonging to another without publish rights
    $this->actingAs($otherUser)->post(route('catalogue.people.assets.add', $person), [
        'accession_number' => 'FA-PERSON-01',
        'relationship_type' => 'depicted',
        'verification_status' => 'unverified',
    ])->assertForbidden();
});
