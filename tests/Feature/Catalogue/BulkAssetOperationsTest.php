<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Collection;
use App\Modules\Ingest\Models\AssetAuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createBulkTestUser(string $roleKey, array $permissions): User
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

it('allows previewing bulk selection and denies unauthorized assets', function (): void {
    $user1 = createBulkTestUser('user1', ['assets.view', 'assets.update']);
    $user2 = createBulkTestUser('user2', ['assets.view', 'assets.update']);

    $asset1 = Asset::query()->create(['accession_number' => 'FA-BULK-01', 'title' => 'Foto 1', 'created_by_user_id' => $user1->id]);
    $asset2 = Asset::query()->create(['accession_number' => 'FA-BULK-02', 'title' => 'Foto 2', 'created_by_user_id' => $user2->id]);

    // User 1 previewing own asset succeeds
    $this->actingAs($user1)
        ->get(route('catalogue.bulk.confirm', ['asset_ids' => [$asset1->id]]))
        ->assertOk()
        ->assertSee('Foto 1')
        ->assertSee('FA-BULK-01');

    // User 1 previewing asset owned by user 2 is denied with 403
    $this->actingAs($user1)
        ->get(route('catalogue.bulk.confirm', ['asset_ids' => [$asset1->id, $asset2->id]]))
        ->assertForbidden();
});

it('applies bulk updates atomically, increments lock versions, and creates audit events', function (): void {
    $admin = createBulkTestUser('admin', ['assets.view', 'assets.update', 'assets.publish']);

    $asset1 = Asset::query()->create([
        'accession_number' => 'FA-BULK-03',
        'title' => 'Foto 3',
        'lock_version' => 1,
        'created_by_user_id' => $admin->id,
    ]);
    $asset2 = Asset::query()->create([
        'accession_number' => 'FA-BULK-04',
        'title' => 'Foto 4',
        'lock_version' => 1,
        'created_by_user_id' => $admin->id,
    ]);

    $collection = Collection::query()->create(['title' => 'Bulk Collectie', 'slug' => 'bulk-collectie']);

    $response = $this->actingAs($admin)->post(route('catalogue.bulk.apply'), [
        'asset_ids' => [$asset1->id, $asset2->id],
        'lock_versions' => [
            $asset1->id => 1,
            $asset2->id => 1,
        ],
        'tags_to_add' => 'geschiedenis, plein',
        'collection_id_to_add' => $collection->id,
        'update_rights' => 1,
        'rights_status' => 'verified',
        'rights_holder' => 'Stadsarchief',
        'rights_note' => 'Geverifieerd in batch',
        'update_dates' => 1,
        'date_precision' => 'year',
        'date_earliest' => '1935-01-01',
        'date_latest' => '1935-12-31',
        'date_display' => 'ca. 1935',
        'update_status' => 1,
        'catalogue_status' => 'catalogued',
    ]);

    $response->assertRedirect(route('admin.assets.index'));

    $asset1->refresh();
    $asset2->refresh();

    // Verify lock versions incremented
    expect($asset1->lock_version)->toBe(2);
    expect($asset2->lock_version)->toBe(2);

    // Verify tags attached
    expect($asset1->tags->pluck('name')->all())->toContain('geschiedenis', 'plein');
    expect($asset2->tags->pluck('name')->all())->toContain('geschiedenis', 'plein');

    // Verify collection attached
    expect($asset1->collections->pluck('id')->all())->toContain($collection->id);
    expect($asset2->collections->pluck('id')->all())->toContain($collection->id);

    // Verify rights & dates & status
    expect($asset1->rights->first()->verification_status)->toBe('verified');
    expect($asset1->rights->first()->rights_holder)->toBe('Stadsarchief');
    expect($asset1->catalogue_status)->toBe('catalogued');
    expect($asset1->date_display)->toBe('ca. 1935');

    // Verify audit logs created
    $audit1 = AssetAuditEvent::query()->where('asset_id', $asset1->id)->where('event_type', 'metadata.bulk_updated')->first();
    expect($audit1)->not->toBeNull();
    expect($audit1->details['revision'])->toBe(2);
    expect($audit1->actor_user_id)->toBe($admin->id);
});

it('prevents bulk updates on optimistic lock version mismatch', function (): void {
    $admin = createBulkTestUser('admin', ['assets.view', 'assets.update', 'assets.publish']);

    $asset = Asset::query()->create([
        'accession_number' => 'FA-BULK-05',
        'title' => 'Foto 5',
        'lock_version' => 2, // Database already at version 2
        'created_by_user_id' => $admin->id,
    ]);

    $response = $this->actingAs($admin)->post(route('catalogue.bulk.apply'), [
        'asset_ids' => [$asset->id],
        'lock_versions' => [
            $asset->id => 1, // Stale version 1 submitted
        ],
        'tags_to_add' => 'stale-tag',
    ]);

    $response->assertSessionHasErrors('lock_versions');

    $asset->refresh();
    expect($asset->lock_version)->toBe(2);
    expect($asset->tags)->toBeEmpty();
});
