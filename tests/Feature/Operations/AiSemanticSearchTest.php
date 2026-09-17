<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Ai\Models\AiEmbedding;
use App\Modules\Ai\Models\AiEmbeddingGeneration;
use App\Modules\Ai\Services\AiConfigurationService;
use App\Modules\Ai\Services\AiSemanticSearchService;
use App\Modules\Ai\Services\PgvectorEmbeddingStore;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;

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
    ])->fresh();
    DB::table('ai_embedding_heads')->insert(['provider_kind' => 'local', 'generation_id' => $this->generation->id]);
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

it('refuses unavailable vector storage before sending the query to a provider', function (): void {
    semanticAsset($this->reviewer, 'SEM-OWN', [1.0, 0.0]);
    semanticAsset($this->otherUser, 'SEM-HIDDEN', [1.0, 0.0]);
    Http::fake([
        'http://127.0.0.1:8088/v1/embed-text' => Http::response([
            'embedding' => [1.0, 0.0],
            'model_space' => 'clip-nl-proof-space',
            'dimensions' => 2,
        ]),
    ]);

    expect(app(PgvectorEmbeddingStore::class)->available())->toBeFalse()
        ->and(fn () => app(AiSemanticSearchService::class)->searchAdmin('dorpsplein', 'local', $this->reviewer))
        ->toThrow(ValidationException::class, 'pgvector is niet beschikbaar');
    Http::assertNothingSent();
});

it('does not expose semantic results from the removed JSON backend', function (): void {
    $this->partialMock(PgvectorEmbeddingStore::class, function (MockInterface $mock): void {
        $mock->shouldReceive('requireAvailable')->andReturnNull();
    });
    semanticAsset($this->reviewer, 'SEM-ROUTE', [1.0, 0.0]);
    Http::fake([
        'http://127.0.0.1:8088/v1/embed-text' => Http::response([
            'embedding' => [1.0, 0.0],
            'model_space' => 'clip-nl-proof-space',
            'dimensions' => 2,
        ]),
    ]);

    $this->actingAs($this->reviewer)->get('/admin/operations/ai/search?q=dorpsplein&provider=local')
        ->assertRedirect()
        ->assertSessionHasErrors('query');
    Http::assertNothingSent();
});

it('refuses text embeddings from a different model space or dimension than the active image index', function (array $response, string $expected): void {
    $this->generation->update(['vector_backend' => 'pgvector']);
    $this->partialMock(PgvectorEmbeddingStore::class, function (MockInterface $mock): void {
        $mock->shouldReceive('requireAvailable')->andReturnNull();
    });
    semanticAsset($this->reviewer, 'SEM-MISMATCH', [1.0, 0.0]);
    Http::fake(['http://127.0.0.1:8088/v1/embed-text' => Http::response($response)]);

    expect(fn () => app(AiSemanticSearchService::class)->searchAdmin('dorpsplein', 'local', $this->reviewer))
        ->toThrow(ValidationException::class, $expected);
})->with([
    'model space mismatch' => [[
        'embedding' => [1.0, 0.0],
        'model_space' => 'text-only-space',
        'dimensions' => 2,
    ], 'geen actieve beeldindex'],
    'dimension mismatch' => [[
        'embedding' => [1.0, 0.0, 0.0],
        'model_space' => 'clip-nl-proof-space',
        'dimensions' => 3,
    ], 'verschillende embeddingdimensies'],
]);
