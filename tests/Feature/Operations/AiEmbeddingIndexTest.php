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
use App\Modules\Ai\Services\PgvectorEmbeddingStore;
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
    $image = imagecreatetruecolor(800, 600);
    imagefill($image, 0, 0, 0xAABBCC);
    ob_start();
    imagejpeg($image, null, 90);
    $derivative = ob_get_clean();
    imagedestroy($image);
    Storage::disk('local')->put('derivatives/index-ai-preview-1200.jpg', $derivative);
    $this->file = AssetFile::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/index-ai.jpg',
        'sha256' => hash('sha256', 'image-index-bytes'),
        'media_type' => 'image/jpeg',
        'byte_size' => 12345,
        'ingest_status' => 'ready_private',
        'scanner_status' => 'clean',
        'derivatives' => ['preview1200' => 'derivatives/index-ai-preview-1200.jpg'],
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

it('refuses dispatch without the vector backend and never queues unusable index work', function (): void {
    Queue::fake();

    expect(app(PgvectorEmbeddingStore::class)->available())->toBeFalse()
        ->and(fn () => app(AiDispatchService::class)->dispatchEmbeddingIndex([$this->asset->id], 'local', $this->user))
        ->toThrow(ValidationException::class, 'pgvector is niet beschikbaar');
    Queue::assertNothingPushed();
});

it('does not silently index into JSON storage when pgvector is unavailable', function (): void {
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

    expect(fn () => (new ProcessAiIndexJob($run->id))->handle())
        ->toThrow(ValidationException::class, 'pgvector is niet beschikbaar');
    expect(AiEmbedding::query()->count())->toBe(0)
        ->and(AiRun::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps a disabled embedding capability blocked before checking the vector backend', function (): void {
    AiEmbeddingGeneration::query()->create([
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

    app(AiConfigurationService::class)->update([
        'global_enabled' => '1',
        'embeddings_enabled' => false,
        'local_provider_enabled' => '1',
        'local_endpoint' => 'http://127.0.0.1:8088',
        'embeddings_provider' => 'local',
        'max_assets_per_batch' => 10,
        'derivative_max_pixels' => 512,
        'request_timeout_seconds' => 15,
    ], $this->user);

    (new ProcessAiIndexJob($run->id))->handle();

    expect($run->fresh()->status)->toBe(OperationRun::STATUS_CANCELLED)
        ->and(AiEmbedding::query()->count())->toBe(0);
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
