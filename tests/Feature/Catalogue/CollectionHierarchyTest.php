<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createTestUser(string $roleKey, array $permissions): User
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

it('allows viewing collections and catalogue overview', function (): void {
    $viewer = createTestUser('viewer', ['assets.view']);
    $collection = Collection::query()->create([
        'title' => 'Historisch Centrum',
        'slug' => 'historisch-centrum',
        'collection_type' => 'collection',
    ]);

    $this->actingAs($viewer)
        ->get(route('catalogue.index'))
        ->assertOk()
        ->assertSee('Catalogus')
        ->assertSee('Collecties');

    $this->actingAs($viewer)
        ->get(route('catalogue.collections.index'))
        ->assertOk()
        ->assertSee('Historisch Centrum');

    $this->actingAs($viewer)
        ->get(route('catalogue.collections.show', $collection))
        ->assertOk()
        ->assertSee('Historisch Centrum');
});

it('creates collections and nested album hierarchy and prevents cyclical parents', function (): void {
    $admin = createTestUser('admin', ['assets.view', 'collections.manage', 'assets.update']);

    // Create root collection
    $response = $this->actingAs($admin)->post(route('catalogue.collections.store'), [
        'title' => 'Gemeentearchief',
        'collection_type' => 'collection',
        'description' => 'Hoofdcollectie documenten',
    ]);
    $response->assertSessionHasNoErrors();
    $root = Collection::query()->where('slug', 'gemeentearchief')->firstOrFail();

    // Create child album
    $responseChild = $this->actingAs($admin)->post(route('catalogue.collections.store'), [
        'title' => 'Fotoalbum 1920',
        'collection_type' => 'album',
        'parent_id' => $root->id,
        'position' => 1,
    ]);
    $responseChild->assertSessionHasNoErrors();
    $child = Collection::query()->where('slug', 'fotoalbum-1920')->firstOrFail();
    expect($child->parent_id)->toBe($root->id);

    // Create grandchild series
    $responseGrandchild = $this->actingAs($admin)->post(route('catalogue.collections.store'), [
        'title' => 'Marktplein Serie',
        'collection_type' => 'series',
        'parent_id' => $child->id,
        'position' => 1,
    ]);
    $responseGrandchild->assertSessionHasNoErrors();
    $grandchild = Collection::query()->where('slug', 'marktplein-serie')->firstOrFail();

    // Attempt cyclical parent: setting root's parent to grandchild should fail validation
    $cycleResponse = $this->actingAs($admin)->put(route('catalogue.collections.update', $root), [
        'title' => 'Gemeentearchief',
        'slug' => 'gemeentearchief',
        'collection_type' => 'collection',
        'parent_id' => $grandchild->id,
    ]);
    $cycleResponse->assertSessionHasErrors('parent_id');

    // Setting parent to itself should also fail
    $selfCycleResponse = $this->actingAs($admin)->put(route('catalogue.collections.update', $root), [
        'title' => 'Gemeentearchief',
        'slug' => 'gemeentearchief',
        'collection_type' => 'collection',
        'parent_id' => $root->id,
    ]);
    $selfCycleResponse->assertSessionHasErrors('parent_id');
});

it('associates photos, orders positions, moves photos between collections, and enforces asset permissions', function (): void {
    $admin = createTestUser('admin', ['assets.view', 'collections.manage', 'assets.update', 'assets.publish']);
    $otherUser = createTestUser('volunteer', ['assets.view', 'assets.update']);

    $colA = Collection::query()->create(['title' => 'Collectie A', 'slug' => 'col-a', 'collection_type' => 'collection']);
    $colB = Collection::query()->create(['title' => 'Collectie B', 'slug' => 'col-b', 'collection_type' => 'collection']);

    $asset1 = Asset::query()->create(['accession_number' => 'FA-001', 'title' => 'Foto 1', 'created_by_user_id' => $admin->id]);
    $asset2 = Asset::query()->create(['accession_number' => 'FA-002', 'title' => 'Foto 2', 'created_by_user_id' => $admin->id]);
    $privateAssetOther = Asset::query()->create(['accession_number' => 'FA-PRIV', 'title' => 'Privé foto', 'created_by_user_id' => $otherUser->id]);

    // Add asset1 and asset2 to colA
    $this->actingAs($admin)->post(route('catalogue.collections.assets.add', $colA), [
        'accession_number' => 'FA-001',
        'position' => 1,
        'note' => 'Eerste foto',
    ])->assertSessionHasNoErrors();

    $this->actingAs($admin)->post(route('catalogue.collections.assets.add', $colA), [
        'accession_number' => 'FA-002',
        'position' => 2,
        'note' => 'Tweede foto',
    ])->assertSessionHasNoErrors();

    expect($colA->assets()->count())->toBe(2);

    // Reorder photos in colA
    $this->actingAs($admin)->post(route('catalogue.collections.reorder', $colA), [
        'ordered_asset_ids' => [$asset2->id, $asset1->id],
    ])->assertSessionHasNoErrors();

    $reordered = $colA->assets()->get();
    expect($reordered->first()->id)->toBe($asset2->id)
        ->and((int) $reordered->first()->pivot->position)->toBe(1);

    // Move asset1 from colA to colB
    $this->actingAs($admin)->post(route('catalogue.collections.assets.move', [$colA, $asset1]), [
        'target_collection_id' => $colB->id,
    ])->assertSessionHasNoErrors();

    expect($colA->assets()->count())->toBe(1)
        ->and($colB->assets()->count())->toBe(1)
        ->and($colB->assets()->first()->id)->toBe($asset1->id);

    // User without view/update permission to a private photo cannot attach it
    $this->actingAs($otherUser)->post(route('catalogue.collections.assets.add', $colA), [
        'accession_number' => 'FA-001',
    ])->assertForbidden();
});
