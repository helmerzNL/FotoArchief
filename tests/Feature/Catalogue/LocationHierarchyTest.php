<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createLocationTestUser(string $roleKey, array $permissions): User
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

it('creates hierarchical locations with historical aliases and prevents cyclical parents', function (): void {
    $admin = createLocationTestUser('admin', ['assets.view', 'catalogue.manage', 'assets.update']);

    // Create Country
    $this->actingAs($admin)->post(route('catalogue.locations.store'), [
        'name' => 'Nederland',
        'location_type' => 'country',
    ])->assertSessionHasNoErrors();

    $country = Location::query()->where('name', 'Nederland')->firstOrFail();

    // Create City under Country
    $this->actingAs($admin)->post(route('catalogue.locations.store'), [
        'name' => 'Eindhoven',
        'location_type' => 'city',
        'parent_id' => $country->id,
        'latitude' => '51.441642',
        'longitude' => '5.469722',
        'aliases' => 'Endhoven, Eyndhoven',
    ])->assertSessionHasNoErrors();

    $city = Location::query()->where('name', 'Eindhoven')->firstOrFail();
    expect($city->parent_id)->toBe($country->id)
        ->and($city->aliases()->count())->toBe(2);

    // Create Street under City
    $this->actingAs($admin)->post(route('catalogue.locations.store'), [
        'name' => 'Markt',
        'location_type' => 'street',
        'parent_id' => $city->id,
        'historical_period' => '1800-heden',
        'aliases' => 'Groote Markt',
    ])->assertSessionHasNoErrors();

    $street = Location::query()->where('name', 'Markt')->firstOrFail();
    expect($street->fullPath())->toBe('Nederland › Eindhoven › Markt');

    // Attempt cyclical parent assignment
    $this->actingAs($admin)->put(route('catalogue.locations.update', $country), [
        'name' => 'Nederland',
        'location_type' => 'country',
        'parent_id' => $street->id,
    ])->assertSessionHasErrors('parent_id');

    // Search by historical alias
    $this->actingAs($admin)
        ->get(route('catalogue.locations.index', ['q' => 'Eyndhoven']))
        ->assertOk()
        ->assertSee('Eindhoven');
});

it('associates photos to locations with uncertainty and enforces permission guards', function (): void {
    $admin = createLocationTestUser('admin', ['assets.view', 'catalogue.manage', 'assets.update', 'assets.publish']);
    $viewer = createLocationTestUser('viewer', ['assets.view']);
    $otherUser = createLocationTestUser('volunteer', ['assets.view', 'assets.update']);

    $location = Location::query()->create([
        'name' => 'Oude Kerk',
        'normalized_name' => 'oude kerk',
        'location_type' => 'building',
    ]);

    $asset = Asset::query()->create([
        'accession_number' => 'FA-LOC-01',
        'title' => 'Kerkplein met toren',
        'created_by_user_id' => $admin->id,
    ]);

    // Attach photo with depicted_place, confidence, and verification status
    $this->actingAs($admin)->post(route('catalogue.locations.assets.add', $location), [
        'accession_number' => 'FA-LOC-01',
        'relationship_type' => 'depicted_place',
        'confidence' => '0.80',
        'verification_status' => 'verified',
        'note' => 'Toren duidelijk herkenbaar',
    ])->assertSessionHasNoErrors();

    $attached = $location->assets()->first();
    expect($attached)->not->toBeNull()
        ->and($attached->pivot->relationship_type)->toBe('depicted_place')
        ->and((float) $attached->pivot->confidence)->toBe(0.8)
        ->and($attached->pivot->verification_status)->toBe('verified');

    // Viewer cannot attach photos
    $this->actingAs($viewer)->post(route('catalogue.locations.assets.add', $location), [
        'accession_number' => 'FA-LOC-01',
        'relationship_type' => 'depicted_place',
        'verification_status' => 'unverified',
    ])->assertForbidden();

    // User cannot attach private photo belonging to another
    $this->actingAs($otherUser)->post(route('catalogue.locations.assets.add', $location), [
        'accession_number' => 'FA-LOC-01',
        'relationship_type' => 'depicted_place',
        'verification_status' => 'unverified',
    ])->assertForbidden();
});
