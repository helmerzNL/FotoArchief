<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Ai\Models\AiEmbedding;
use App\Modules\Ai\Models\AiEmbeddingGeneration;
use App\Modules\Ai\Services\AiConfigurationService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\Collection;
use App\Modules\Catalogue\Models\Tag;
use App\Modules\Publication\Models\Publication;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

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

it('semantic public search only renders publicly visible publication candidates', function (): void {
    $owner = discoveryOwner();
    app(AiConfigurationService::class)->update([
        'global_enabled' => '1',
        'embeddings_enabled' => '1',
        'local_provider_enabled' => '1',
        'local_endpoint' => 'http://127.0.0.1:8088',
        'max_assets_per_batch' => 10,
        'derivative_max_pixels' => 512,
        'request_timeout_seconds' => 15,
        'monthly_external_budget_cents' => 0,
    ], $owner);
    $generation = AiEmbeddingGeneration::query()->create([
        'provider_kind' => 'local',
        'provider_name' => 'owned-http',
        'model_id' => 'clip-public-proof',
        'model_space' => 'clip-public-proof',
        'dimensions' => 2,
        'distance_metric' => 'cosine',
        'vector_backend' => 'database_json',
        'status' => AiEmbeddingGeneration::STATUS_ACTIVE,
        'activated_at' => now(),
    ]);
    $visible = discoveryPublishedAsset($owner, 'Publiek plein');
    $revoked = discoveryPublishedAsset($owner, 'Verborgen plein', ['status' => 'revoked', 'revoked_at' => now()]);
    foreach ([[$visible, [1.0, 0.0]], [$revoked, [1.0, 0.0]]] as [$asset, $vector]) {
        $file = $asset->files()->firstOrFail();
        AiEmbedding::query()->create([
            'ai_embedding_generation_id' => $generation->id,
            'asset_id' => $asset->id,
            'asset_file_id' => $file->id,
            'source_asset_lock_version' => $asset->lock_version,
            'source_file_sha256' => $file->sha256,
            'embedding' => $vector,
            'indexed_at' => now(),
            'metadata' => ['modality' => 'image'],
        ]);
    }
    Http::fake([
        'http://127.0.0.1:8088/v1/embed-text' => Http::response([
            'embedding' => [1.0, 0.0],
            'model_space' => 'clip-public-proof',
            'dimensions' => 2,
        ]),
    ]);

    $this->get('/ontdek?semantic_q=plein&semantic_provider=local')
        ->assertOk()
        ->assertSee('Publiek plein')
        ->assertDontSee('Verborgen plein');
});

it('applies every public visibility guard before semantic results are counted', function (): void {
    $owner = discoveryOwner();
    app(AiConfigurationService::class)->update([
        'global_enabled' => '1', 'embeddings_enabled' => '1', 'local_provider_enabled' => '1',
        'local_endpoint' => 'http://127.0.0.1:8088', 'max_assets_per_batch' => 10,
        'derivative_max_pixels' => 512, 'request_timeout_seconds' => 15, 'monthly_external_budget_cents' => 0,
    ], $owner);
    $generation = AiEmbeddingGeneration::query()->create([
        'provider_kind' => 'local', 'provider_name' => 'owned-http', 'model_id' => 'visibility-proof',
        'model_space' => 'visibility-proof', 'dimensions' => 2, 'distance_metric' => 'cosine',
        'vector_backend' => 'database_json', 'status' => AiEmbeddingGeneration::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $visible = discoveryPublishedAsset($owner, 'Zichtbaar resultaat');
    $denied = [
        discoveryPublishedAsset($owner, 'Ingetrokken resultaat', ['status' => 'revoked', 'revoked_at' => now()]),
        discoveryPublishedAsset($owner, 'Privacy resultaat', ['privacy_cleared' => false]),
        discoveryPublishedAsset($owner, 'Embargo resultaat', ['embargo_until' => now()->addDay()->toDateString()]),
        discoveryPublishedAsset($owner, 'Versie resultaat', ['published_lock_version' => 99]),
        discoveryPublishedAsset($owner, 'Prullenbak resultaat'),
    ];
    $denied[4]->delete();
    foreach (array_merge([$visible], $denied) as $asset) {
        $file = $asset->files()->firstOrFail();
        AiEmbedding::query()->create([
            'ai_embedding_generation_id' => $generation->id, 'asset_id' => $asset->id, 'asset_file_id' => $file->id,
            'source_asset_lock_version' => $asset->lock_version, 'source_file_sha256' => $file->sha256,
            'embedding' => [1.0, 0.0], 'indexed_at' => now(), 'metadata' => ['modality' => 'image'],
        ]);
    }
    Http::fake(['http://127.0.0.1:8088/v1/embed-text' => Http::response([
        'embedding' => [1.0, 0.0], 'model_space' => 'visibility-proof', 'dimensions' => 2,
    ])]);

    $response = $this->get('/ontdek?semantic_q=resultaat&semantic_consent=1');
    $response->assertOk()->assertSee('Zichtbaar resultaat');
    foreach (['Ingetrokken resultaat', 'Privacy resultaat', 'Embargo resultaat', 'Versie resultaat', 'Prullenbak resultaat'] as $title) {
        $response->assertDontSee($title);
    }
    expect($response->viewData('publications'))->toHaveCount(1);
});

it('does not send a visitor query to an AI provider without per-request consent', function (): void {
    Http::fake();

    $this->get('/ontdek?semantic_q=priv%C3%A9+zoekvraag')
        ->assertOk()
        ->assertSee('Geef eerst toestemming om je zoektekst naar een AI-provider te sturen voor semantisch zoeken.');

    Http::assertNothingSent();
});
