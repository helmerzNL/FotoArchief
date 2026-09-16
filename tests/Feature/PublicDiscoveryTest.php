<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\Collection;
use App\Modules\Catalogue\Models\Tag;
use App\Modules\Publication\Models\Publication;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function discoveryOwner(): User
{
    $user = User::query()->create(['name' => 'owner', 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', 'editor')->firstOrFail());

    return $user;
}

function discoveryPublishedAsset(User $owner, string $title, array $publicationOverrides = []): Asset
{
    static $sequence = 0;
    $sequence++;
    $asset = Asset::query()->create(['accession_number' => (string) str()->ulid(), 'created_by_user_id' => $owner->id, 'title' => $title, 'lock_version' => 1]);
    AssetFile::query()->create([
        'asset_id' => $asset->id, 'storage_disk' => 'local', 'storage_key' => 'derivatives/'.str()->ulid().'/original.jpg',
        'sha256' => hash('sha256', (string) str()->uuid()), 'media_type' => 'image/jpeg', 'byte_size' => 1000,
        'pixel_width' => 2000, 'pixel_height' => 1000, 'derivatives' => ['preview300' => 'a', 'preview1200' => 'b', 'preview2000' => 'c'],
        'ingest_status' => 'ready_private', 'scanner_status' => 'clean', 'validated_at' => now(), 'processed_at' => now(), 'scanned_at' => now(),
    ]);
    $asset->rights()->create(['verification_status' => 'verified', 'rights_holder' => 'Gemeentearchief']);
    Publication::query()->create(array_replace([
        'asset_id' => $asset->id, 'status' => 'published', 'privacy_cleared' => true, 'published_lock_version' => 1,
        'permalink_slug' => str()->slug($title).'-'.$sequence, 'published_at' => now()->subMinutes($sequence),
    ], $publicationOverrides));

    return $asset;
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('only returns eligible published photos on the public search page', function (): void {
    $owner = discoveryOwner();
    $visible = discoveryPublishedAsset($owner, 'Zichtbare Markt');
    $draft = Asset::query()->create(['accession_number' => (string) str()->ulid(), 'created_by_user_id' => $owner->id, 'title' => 'Concept foto', 'lock_version' => 1]);
    $embargoed = discoveryPublishedAsset($owner, 'Embargofoto', ['embargo_until' => now()->addWeek()->toDateString()]);

    $response = $this->get('/ontdek')->assertOk();
    $response->assertSee('Zichtbare Markt');
    $response->assertDontSee('Concept foto');
    $response->assertDontSee('Embargofoto');
});

it('filters search results by tag', function (): void {
    $owner = discoveryOwner();
    $matching = discoveryPublishedAsset($owner, 'Kermis 1950');
    $tag = Tag::query()->create(['name' => 'kermis', 'slug' => 'kermis']);
    $matching->tags()->attach($tag);
    $other = discoveryPublishedAsset($owner, 'Haven 1950');

    $response = $this->get('/ontdek?tag=kermis')->assertOk();
    $response->assertSee('Kermis 1950');
    $response->assertDontSee('Haven 1950');
});

it('shows only eligible assets on a collection page and lists only collections with public photos', function (): void {
    $owner = discoveryOwner();
    $collection = Collection::query()->create(['title' => 'Marktpleinen', 'slug' => 'marktpleinen', 'collection_type' => 'collection']);
    $emptyCollection = Collection::query()->create(['title' => 'Lege collectie', 'slug' => 'leeg', 'collection_type' => 'collection']);
    $inCollection = discoveryPublishedAsset($owner, 'Plein foto');
    $collection->assets()->attach($inCollection->id);
    $notInCollection = discoveryPublishedAsset($owner, 'Ander plein');

    $this->get('/collecties')->assertOk()->assertSee('Marktpleinen')->assertDontSee('Lege collectie');

    $response = $this->get('/collecties/marktpleinen')->assertOk();
    $response->assertSee('Plein foto');
    $response->assertDontSee('Ander plein');
});

it('paginates public search with a keyset cursor instead of skipping or repeating photos', function (): void {
    $owner = discoveryOwner();
    $titles = [];
    for ($i = 0; $i < 30; $i++) {
        $title = 'Foto nummer '.$i;
        $titles[] = $title;
        discoveryPublishedAsset($owner, $title);
    }

    $first = $this->get('/ontdek')->assertOk();
    $firstIds = collect($first->viewData('publications'))->pluck('id')->all();
    $cursor = $first->viewData('nextCursor');
    expect($cursor)->not->toBeNull()->and($firstIds)->toHaveCount(24);

    $second = $this->get('/ontdek?cursor='.$cursor)->assertOk();
    $secondIds = collect($second->viewData('publications'))->pluck('id')->all();
    expect($secondIds)->toHaveCount(6);
    expect(array_intersect($firstIds, $secondIds))->toBeEmpty();
});
