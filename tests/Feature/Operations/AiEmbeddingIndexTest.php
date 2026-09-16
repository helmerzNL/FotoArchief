<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Ai\Jobs\ProcessAiIndexJob;
use App\Modules\Ai\Models\AiEmbedding;
use App\Modules\Ai\Models\AiEmbeddingGeneration;
use App\Modules\Ai\Models\AiRun;
use App\Modules\Ai\Services\AiConfigurationService;
use App\Modules\Ai\Services\AiDispatchService;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    Storage::fake('local');

    $this->user = User::query()->create([
        'name' => 'AI Index Admin',
        'email' => 'ai-index@example.test',
        'password' => Hash::make('secret12345'),
    ]);
    $this->user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());

    $this->asset = Asset::query()->create([
        'accession_number' => 'AI-INDEX-'.(string) str()->ulid(),
        'title' => 'Index contract',
        'created_by_user_id' => $this->user->id,
    ])->fresh();
    Storage::disk('local')->put('originals/index-ai.jpg', 'image-index-bytes');
    $this->file = AssetFile::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/index-ai.jpg',
        'sha256' => hash('sha256', 'image-index-bytes'),
        'media_type' => 'image/jpeg',
        'byte_size' => 12345,
        'ingest_status' => 'ready_private',
        'scanner_status' => 'clean',
        'is_primary' => true,
    ]);

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
    ], $this->user);
});

it('dispatches embedding index runs on the ingest queue', function (): void {
    Queue::fake();

    $run = app(AiDispatchService::class)->dispatchEmbeddingIndex([$this->asset->id], 'local', $this->user);

    expect($run->operation_type)->toBe(ProcessAiIndexJob::TYPE)
        ->and($run->payload['provider'])->toBe('local')
        ->and($run->total_items)->toBe(1);
    Queue::assertPushed(ProcessAiIndexJob::class);
});

it('stores source-bound image embeddings in a shared multimodal model space', function (): void {
    Http::fake([
        'http://127.0.0.1:8088/v1/embed-image' => Http::response([
            'embedding' => [0.25, 0.5, 0.75],
            'model_space' => 'clip-nl-proof-space',
            'dimensions' => 3,
        ]),
    ]);

    $run = OperationRun::query()->create([
        'operation_type' => ProcessAiIndexJob::TYPE,
        'status' => OperationRun::STATUS_QUEUED,
        'requested_by_user_id' => $this->user->id,
        'payload' => ['asset_ids' => [$this->asset->id], 'provider' => 'local', 'cursor' => 0],
        'total_items' => 1,
    ]);

    (new ProcessAiIndexJob($run->id))->handle();

    $generation = AiEmbeddingGeneration::query()->where('model_space', 'clip-nl-proof-space')->firstOrFail();
    $embedding = AiEmbedding::query()->where('asset_file_id', $this->file->id)->firstOrFail();
    expect($run->fresh()->status)->toBe(OperationRun::STATUS_COMPLETED)
        ->and($generation->status)->toBe(AiEmbeddingGeneration::STATUS_ACTIVE)
        ->and($generation->dimensions)->toBe(3)
        ->and($generation->capability_receipt['text_embeddings_required_for_queries'])->toBeTrue()
        ->and($embedding->source_file_sha256)->toBe($this->file->sha256)
        ->and($embedding->embedding)->toBe([0.25, 0.5, 0.75])
        ->and(AiRun::query()->where('run_type', AiRun::TYPE_EMBEDDING)->count())->toBe(1);
});

it('refuses embedding index batches when embeddings are disabled', function (): void {
    app(AiConfigurationService::class)->update([
        'global_enabled' => '1',
        'embeddings_enabled' => false,
        'local_provider_enabled' => '1',
        'local_endpoint' => 'http://127.0.0.1:8088',
        'embeddings_provider' => 'local',
        'max_assets_per_batch' => 10,
        'derivative_max_pixels' => 512,
        'request_timeout_seconds' => 15,
        'monthly_external_budget_cents' => 0,
    ], $this->user);

    expect(fn () => app(AiDispatchService::class)->dispatchEmbeddingIndex([$this->asset->id], 'local', $this->user))
        ->toThrow(ValidationException::class, 'AI-embeddings zijn niet actief');
});
