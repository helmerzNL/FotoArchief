<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Ai\Models\AiEmbedding;
use App\Modules\Ai\Models\AiEmbeddingGeneration;
use App\Modules\Ai\Services\AiConfigurationService;
use App\Modules\Ai\Services\AiSemanticSearchService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);

    $this->reviewer = User::query()->create([
        'name' => 'Semantic Reviewer',
        'email' => 'semantic-reviewer@example.test',
        'password' => Hash::make('secret12345'),
    ]);
    $this->reviewer->roles()->attach(Role::query()->where('key', 'viewer')->firstOrFail());

    $this->otherUser = User::query()->create([
        'name' => 'Other Owner',
        'email' => 'other-owner@example.test',
        'password' => Hash::make('secret12345'),
    ]);
    $this->otherUser->roles()->attach(Role::query()->where('key', 'viewer')->firstOrFail());

    app(AiConfigurationService::class)->update([
        'global_enabled' => '1',
        'embeddings_enabled' => '1',
        'local_provider_enabled' => '1',
        'local_endpoint' => 'http://127.0.0.1:8088',
        'embeddings_provider' => 'local',
        'max_assets_per_batch' => 10,
        'derivative_max_pixels' => 512,
        'request_timeout_seconds' => 15,
        'monthly_external_budget_cents' => 0,
    ], $this->reviewer);

    $this->generation = AiEmbeddingGeneration::query()->create([
        'provider_kind' => 'local',
        'provider_name' => 'owned-http',
        'model_id' => 'clip-nl-proof-space',
        'model_space' => 'clip-nl-proof-space',
        'dimensions' => 2,
        'distance_metric' => 'cosine',
        'vector_backend' => 'database_json',
        'status' => AiEmbeddingGeneration::STATUS_ACTIVE,
        'activated_at' => now(),
    ]);
});

function semanticAsset(User $owner, string $accession, array $embedding): Asset
{
    $asset = Asset::query()->create([
        'accession_number' => $accession,
        'title' => 'Semantic '.$accession,
        'created_by_user_id' => $owner->id,
    ])->fresh();
    $file = AssetFile::query()->create([
        'asset_id' => $asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/'.$accession.'.jpg',
        'sha256' => hash('sha256', $accession),
        'media_type' => 'image/jpeg',
        'byte_size' => 100,
        'ingest_status' => 'ready_private',
        'scanner_status' => 'clean',
        'is_primary' => true,
    ]);
    AiEmbedding::query()->create([
        'ai_embedding_generation_id' => test()->generation->id,
        'asset_id' => $asset->id,
        'asset_file_id' => $file->id,
        'source_asset_lock_version' => $asset->lock_version,
        'source_file_sha256' => $file->sha256,
        'embedding' => $embedding,
        'indexed_at' => now(),
        'metadata' => ['modality' => 'image'],
    ]);

    return $asset;
}

it('ranks only assets visible to the staff user', function (): void {
    $visible = semanticAsset($this->reviewer, 'SEM-OWN', [1.0, 0.0]);
    semanticAsset($this->otherUser, 'SEM-HIDDEN', [1.0, 0.0]);
    Http::fake([
        'http://127.0.0.1:8088/v1/embed-text' => Http::response([
            'embedding' => [1.0, 0.0],
            'model_space' => 'clip-nl-proof-space',
            'dimensions' => 2,
        ]),
    ]);

    $results = app(AiSemanticSearchService::class)->searchAdmin('dorpsplein', 'local', $this->reviewer);

    expect($results)->toHaveCount(1)
        ->and($results[0]['asset_id'])->toBe($visible->id)
        ->and($results[0]['score'])->toBe(1.0);
});

it('renders admin semantic search results', function (): void {
    semanticAsset($this->reviewer, 'SEM-ROUTE', [1.0, 0.0]);
    Http::fake([
        'http://127.0.0.1:8088/v1/embed-text' => Http::response([
            'embedding' => [1.0, 0.0],
            'model_space' => 'clip-nl-proof-space',
            'dimensions' => 2,
        ]),
    ]);

    $this->actingAs($this->reviewer)->get('/admin/operations/ai/search?q=dorpsplein&provider=local')
        ->assertOk()
        ->assertSee('AI semantisch zoeken', false)
        ->assertSee('SEM-ROUTE', false);
});
