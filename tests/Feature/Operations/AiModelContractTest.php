<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Ai\Models\AiEmbedding;
use App\Modules\Ai\Models\AiEmbeddingGeneration;
use App\Modules\Ai\Models\AiRun;
use App\Modules\Ai\Models\AiSuggestion;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->user = User::query()->create([
        'name' => 'AI Contract User',
        'email' => 'ai-contract@example.test',
        'password' => Hash::make('secret12345'),
    ]);
    $this->user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());

    $this->asset = Asset::query()->create([
        'accession_number' => 'AI-'.(string) str()->ulid(),
        'title' => 'AI contract test',
        'created_by_user_id' => $this->user->id,
    ])->fresh();
    $this->file = AssetFile::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/ai-contract.jpg',
        'sha256' => str_repeat('a', 64),
        'media_type' => 'image/jpeg',
        'byte_size' => 12345,
        'ingest_status' => 'ready_private',
        'scanner_status' => 'clean',
        'is_primary' => true,
    ]);
});

it('stores AI run source snapshots and enforces idempotency keys', function (): void {
    $run = AiRun::query()->create([
        'run_type' => AiRun::TYPE_IMAGE_ANALYSIS,
        'asset_id' => $this->asset->id,
        'asset_file_id' => $this->file->id,
        'source_asset_lock_version' => $this->asset->lock_version,
        'source_file_sha256' => $this->file->sha256,
        'provider_kind' => 'local',
        'provider_name' => 'owned-http',
        'model_id' => 'clip-proof',
        'model_version' => 'digest:abc123',
        'model_space' => 'clip-proof:512:cosine',
        'idempotency_key' => 'ai-run-'.$this->file->id,
        'requested_by_user_id' => $this->user->id,
        'input_contract' => [
            'derivative' => 'preview',
            'max_pixels' => 1024,
            'metadata_stripped' => true,
        ],
    ]);

    expect($run->matchesCurrentSource($this->file->load('asset')))->toBeTrue()
        ->and($run->asset->is($this->asset))->toBeTrue()
        ->and($run->requestedBy->is($this->user))->toBeTrue();

    AiRun::query()->create(array_merge($run->only([
        'run_type',
        'asset_id',
        'asset_file_id',
        'source_asset_lock_version',
        'source_file_sha256',
        'provider_kind',
        'provider_name',
        'model_id',
        'model_version',
        'model_space',
        'idempotency_key',
        'requested_by_user_id',
        'input_contract',
    ]), ['id' => (string) str()->ulid()]));
})->throws(QueryException::class);

it('keeps suggestions pending until human review', function (): void {
    $run = AiRun::query()->create([
        'run_type' => AiRun::TYPE_IMAGE_ANALYSIS,
        'asset_id' => $this->asset->id,
        'asset_file_id' => $this->file->id,
        'source_asset_lock_version' => $this->asset->lock_version,
        'source_file_sha256' => $this->file->sha256,
        'provider_kind' => 'local',
        'provider_name' => 'owned-http',
        'model_id' => 'caption-proof',
        'idempotency_key' => 'suggestion-'.$this->file->id,
        'input_contract' => ['language' => 'nl'],
    ]);

    $suggestion = AiSuggestion::query()->create([
        'ai_run_id' => $run->id,
        'asset_id' => $this->asset->id,
        'asset_file_id' => $this->file->id,
        'source_asset_lock_version' => $this->asset->lock_version,
        'source_file_sha256' => $this->file->sha256,
        'suggestion_type' => AiSuggestion::TYPE_TAG,
        'value' => 'dorpsstraat',
        'confidence' => 0.8123,
        'evidence' => ['regions' => []],
    ]);

    expect($suggestion->review_status)->toBe(AiSuggestion::REVIEW_PENDING)
        ->and($suggestion->run->is($run))->toBeTrue()
        ->and($this->asset->aiSuggestions()->count())->toBe(1);
});

it('separates embedding generations and detects stale embeddings', function (): void {
    $generation = AiEmbeddingGeneration::query()->create([
        'provider_kind' => 'local',
        'provider_name' => 'owned-http',
        'model_id' => 'openclip-proof',
        'model_version' => 'weights:example',
        'model_space' => 'openclip-proof:512:cosine',
        'dimensions' => 512,
        'distance_metric' => 'cosine',
        'vector_backend' => 'pgvector',
        'capability_receipt' => ['proof_batch' => 10],
    ]);

    $embedding = AiEmbedding::query()->create([
        'ai_embedding_generation_id' => $generation->id,
        'asset_id' => $this->asset->id,
        'asset_file_id' => $this->file->id,
        'source_asset_lock_version' => $this->asset->lock_version,
        'source_file_sha256' => $this->file->sha256,
        'embedding' => [0.1, 0.2, 0.3],
        'indexed_at' => now(),
    ]);

    expect($embedding->matchesCurrentSource($this->file->load('asset')))->toBeTrue()
        ->and($generation->embeddings()->count())->toBe(1);

    $this->asset->forceFill(['lock_version' => $this->asset->lock_version + 1])->save();
    expect($embedding->matchesCurrentSource($this->file->fresh()->load('asset')))->toBeFalse();
});
