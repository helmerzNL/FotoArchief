<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createTagTestUser(string $roleKey, array $permissions): User
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

it('creates tags with synonyms and allows search by name or synonym', function (): void {
    $admin = createTagTestUser('admin', ['assets.view', 'assets.update']);

    $response = $this->actingAs($admin)->post(route('catalogue.tags.store'), [
        'name' => 'Kerkgebouw',
        'description' => 'Religieus gebouw voor erediensten',
        'synonyms' => 'godshuis, bedeplaats, kapel',
    ]);

    $response->assertRedirect();
    $tag = Tag::query()->where('name', 'Kerkgebouw')->first();
    expect($tag)->not->toBeNull();
    expect($tag->slug)->toBe('kerkgebouw');
    expect($tag->synonyms->pluck('name')->all())->toContain('godshuis', 'bedeplaats', 'kapel');

    // Search by synonym finds the tag
    $this->actingAs($admin)
        ->get(route('catalogue.tags.index', ['q' => 'bedeplaats']))
        ->assertOk()
        ->assertSee('Kerkgebouw')
        ->assertSee('bedeplaats');
});

it('updates tag details and syncs synonyms', function (): void {
    $admin = createTagTestUser('admin', ['assets.view', 'assets.update']);

    $tag = Tag::query()->create([
        'name' => 'Oude Markt',
        'slug' => 'oude-markt',
        'description' => 'Historisch marktplein',
    ]);
    $tag->synonyms()->create(['name' => 'Grote Markt', 'slug' => 'grote-markt']);

    $response = $this->actingAs($admin)->put(route('catalogue.tags.update', $tag), [
        'name' => 'Marktplein',
        'description' => 'Vernieuwde omschrijving',
        'synonyms' => 'Grote Markt, Weekmarkt',
    ]);

    $response->assertRedirect(route('catalogue.tags.show', $tag));

    $tag->refresh();
    expect($tag->name)->toBe('Marktplein');
    expect($tag->slug)->toBe('marktplein');
    expect($tag->description)->toBe('Vernieuwde omschrijving');
    expect($tag->synonyms->pluck('name')->all())->toContain('Grote Markt', 'Weekmarkt');
});

it('merges source tag into target tag preserving photo links and making old tag a synonym', function (): void {
    $admin = createTagTestUser('admin', ['assets.view', 'assets.update', 'assets.publish']);

    $sourceTag = Tag::query()->create(['name' => 'Automobiel', 'slug' => 'automobiel']);
    $sourceTag->synonyms()->create(['name' => 'Wagen', 'slug' => 'wagen']);

    $targetTag = Tag::query()->create(['name' => 'Auto', 'slug' => 'auto']);

    // Asset 1 attached to source tag
    $asset1 = Asset::query()->create([
        'accession_number' => 'FA-TAG-01',
        'title' => 'Klassieke automobiel',
        'created_by_user_id' => $admin->id,
    ]);
    $asset1->tags()->attach($sourceTag->id, ['id' => (string) Str::ulid()]);

    // Asset 2 attached to BOTH source and target
    $asset2 = Asset::query()->create([
        'accession_number' => 'FA-TAG-02',
        'title' => 'Twee auto modellen',
        'created_by_user_id' => $admin->id,
    ]);
    $asset2->tags()->attach($sourceTag->id, ['id' => (string) Str::ulid()]);
    $asset2->tags()->attach($targetTag->id, ['id' => (string) Str::ulid()]);

    // Perform merge
    $response = $this->actingAs($admin)->post(route('catalogue.tags.merge', $sourceTag), [
        'target_tag_id' => $targetTag->id,
    ]);

    $response->assertRedirect(route('catalogue.tags.show', $targetTag));

    // Source tag must be deleted
    expect(Tag::query()->find($sourceTag->id))->toBeNull();

    // Target tag must have both assets
    $targetTag->refresh();
    expect($targetTag->assets->pluck('id')->all())->toContain($asset1->id, $asset2->id);

    // Target tag must now have the source tag name and old synonyms as synonyms
    $targetSynonyms = $targetTag->synonyms->pluck('name')->all();
    expect($targetSynonyms)->toContain('Automobiel', 'Wagen');
});
