<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Contributor;
use App\Modules\Catalogue\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createProvenanceTestUser(string $roleKey, array $permissions): User
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

it('creates provenance sources and contributors and associates photos with uncertainty', function (): void {
    $admin = createProvenanceTestUser('admin', ['assets.view', 'catalogue.manage', 'assets.update', 'assets.publish']);

    // Create Source
    $this->actingAs($admin)->post(route('catalogue.sources.store'), [
        'name' => 'Stadsarchief Breda',
        'source_type' => 'archive',
        'reference_code' => 'SAB-1920-FOTO',
        'acquisition_date' => '2020-05-15',
        'custody_history' => 'Overgedragen via gemeentelijke fusie.',
        'description' => 'Fotocollectie openbare werken.',
    ])->assertSessionHasNoErrors();

    $source = Source::query()->where('name', 'Stadsarchief Breda')->firstOrFail();
    expect($source->reference_code)->toBe('SAB-1920-FOTO')
        ->and($source->custody_history)->toBe('Overgedragen via gemeentelijke fusie.');

    // Create Contributor
    $this->actingAs($admin)->post(route('catalogue.contributors.store'), [
        'name' => 'Willem de Groot',
        'contributor_type' => 'photographer',
        'email' => 'willem@degrootfoto.test',
        'contact_details' => 'Atelier Grote Markt 14',
        'note' => 'Lokale beroepsfotograaf 1910-1945.',
    ])->assertSessionHasNoErrors();

    $contributor = Contributor::query()->where('name', 'Willem de Groot')->firstOrFail();

    $asset = Asset::query()->create([
        'accession_number' => 'FA-PROV-01',
        'title' => 'Stadhuisplein 1925',
        'created_by_user_id' => $admin->id,
    ]);

    // Attach asset to Source
    $this->actingAs($admin)->post(route('catalogue.sources.assets.add', $source), [
        'accession_number' => 'FA-PROV-01',
        'relationship_type' => 'provenance',
        'confidence' => '1.00',
        'verification_status' => 'verified',
        'note' => 'Onderdeel van doos 4',
    ])->assertSessionHasNoErrors();

    expect($source->assets()->count())->toBe(1)
        ->and($source->assets()->first()->pivot->relationship_type)->toBe('provenance')
        ->and($source->assets()->first()->pivot->verification_status)->toBe('verified');

    // Attach asset to Contributor
    $this->actingAs($admin)->post(route('catalogue.contributors.assets.add', $contributor), [
        'accession_number' => 'FA-PROV-01',
        'relationship_type' => 'photographer',
        'confidence' => '0.80',
        'verification_status' => 'verified',
    ])->assertSessionHasNoErrors();

    expect($contributor->assets()->count())->toBe(1)
        ->and($contributor->assets()->first()->pivot->relationship_type)->toBe('photographer');

    // Search and filter sources
    $this->actingAs($admin)
        ->get(route('catalogue.sources.index', ['q' => 'SAB-1920']))
        ->assertOk()
        ->assertSee('Stadsarchief Breda');

    // Search and filter contributors
    $this->actingAs($admin)
        ->get(route('catalogue.contributors.index', ['contributor_type' => 'photographer']))
        ->assertOk()
        ->assertSee('Willem de Groot');
});

it('enforces permission guards on provenance and contributor photo attachments', function (): void {
    $admin = createProvenanceTestUser('admin', ['assets.view', 'catalogue.manage', 'assets.update', 'assets.publish']);
    $viewer = createProvenanceTestUser('viewer', ['assets.view']);
    $otherUser = createProvenanceTestUser('volunteer', ['assets.view', 'assets.update']);

    $source = Source::query()->create(['name' => 'Familiearchief', 'source_type' => 'family']);
    $contributor = Contributor::query()->create(['name' => 'Jan Schenker', 'contributor_type' => 'donor']);

    $asset = Asset::query()->create([
        'accession_number' => 'FA-PROV-PRIV',
        'title' => 'Privé foto',
        'created_by_user_id' => $admin->id,
    ]);

    // Viewer cannot attach
    $this->actingAs($viewer)->post(route('catalogue.sources.assets.add', $source), [
        'accession_number' => 'FA-PROV-PRIV',
        'relationship_type' => 'donor',
        'verification_status' => 'unverified',
    ])->assertForbidden();

    $this->actingAs($viewer)->post(route('catalogue.contributors.assets.add', $contributor), [
        'accession_number' => 'FA-PROV-PRIV',
        'relationship_type' => 'donor',
        'verification_status' => 'unverified',
    ])->assertForbidden();

    // Other user cannot attach another user's private photo
    $this->actingAs($otherUser)->post(route('catalogue.sources.assets.add', $source), [
        'accession_number' => 'FA-PROV-PRIV',
        'relationship_type' => 'donor',
        'verification_status' => 'unverified',
    ])->assertForbidden();
});
