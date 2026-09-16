<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Ai\Jobs\ProcessAiAnalysisJob;
use App\Modules\Ai\Models\AiSuggestion;
use App\Modules\Ai\Services\AiConfigurationService;
use App\Modules\Ai\Services\AiDispatchService;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Ingest\Services\MalwareScanner;
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
        'name' => 'AI Async Admin',
        'email' => 'ai-async@example.test',
        'password' => Hash::make('secret12345'),
    ]);
    $this->user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());

    $this->asset = Asset::query()->create([
        'accession_number' => 'AI-ASYNC-'.(string) str()->ulid(),
        'title' => 'Async AI contract',
        'created_by_user_id' => $this->user->id,
    ])->fresh();
    Storage::disk('local')->put('originals/async-ai.jpg', 'safe-derived-image-bytes');
    $image = imagecreatetruecolor(800, 600);
    imagefill($image, 0, 0, 0xAABBCC);
    ob_start();
    imagejpeg($image, null, 90);
    $derivative = ob_get_clean();
    imagedestroy($image);
    Storage::disk('local')->put('derivatives/async-ai-preview-1200.jpg', $derivative);
    $this->file = AssetFile::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/async-ai.jpg',
        'sha256' => hash('sha256', 'safe-derived-image-bytes'),
        'media_type' => 'image/jpeg',
        'byte_size' => 12345,
        'ingest_status' => 'ready_private',
        'scanner_status' => 'clean',
        'derivatives' => ['preview1200' => 'derivatives/async-ai-preview-1200.jpg'],
        'is_primary' => true,
    ]);

    app(AiConfigurationService::class)->update([
        'global_enabled' => '1',
        'image_analysis_enabled' => '1',
        'embeddings_enabled' => '1',
        'local_provider_enabled' => '1',
        'local_endpoint' => 'http://127.0.0.1:8088',
        'image_analysis_provider' => 'local',
        'embeddings_provider' => 'local',
        'max_assets_per_batch' => 10,
        'derivative_max_pixels' => 512,
        'request_timeout_seconds' => 15,
        'monthly_external_budget_cents' => 0,
    ], $this->user);
});

it('dispatches bounded AI analysis as an ingest operation run', function (): void {
    Queue::fake();

    $run = app(AiDispatchService::class)->dispatchImageAnalysis([$this->asset->id], 'local', $this->user);

    expect($run->operation_type)->toBe(ProcessAiAnalysisJob::TYPE)
        ->and($run->status)->toBe(OperationRun::STATUS_QUEUED)
        ->and($run->total_items)->toBe(1)
        ->and($run->payload['provider'])->toBe('local');
    Queue::assertPushed(ProcessAiAnalysisJob::class);
});

it('processes AI analysis in the worker and stores only pending suggestions', function (): void {
    Http::fake([
        'http://127.0.0.1:8088/v1/analyze-image' => Http::response([
            'description' => 'Een veilige testfoto van een dorpsplein.',
            'tags' => ['Dorpsplein', 'Historisch'],
            'model_id' => 'local-caption-proof',
            'model_space' => 'local-caption-proof',
            'confidence' => 0.75,
        ]),
    ]);

    $run = OperationRun::query()->create([
        'operation_type' => ProcessAiAnalysisJob::TYPE,
        'status' => OperationRun::STATUS_QUEUED,
        'requested_by_user_id' => $this->user->id,
        'payload' => ['asset_ids' => [$this->asset->id], 'provider' => 'local', 'cursor' => 0],
        'total_items' => 1,
    ]);

    (new ProcessAiAnalysisJob($run->id))->handle();

    Http::assertSent(function ($request): bool {
        $bytes = base64_decode((string) $request['image_base64'], true);
        $dimensions = is_string($bytes) ? getimagesizefromstring($bytes) : false;

        return is_string($bytes)
            && $bytes !== 'safe-derived-image-bytes'
            && is_array($dimensions)
            && max($dimensions[0], $dimensions[1]) === 512;
    });

    $run->refresh();
    expect($run->status)->toBe(OperationRun::STATUS_COMPLETED)
        ->and($run->processed_items)->toBe(1)
        ->and($this->asset->fresh()->description)->toBeNull()
        ->and(AiSuggestion::query()->where('asset_id', $this->asset->id)->where('review_status', AiSuggestion::REVIEW_PENDING)->count())->toBe(3);
});

it('reports an explicit failure when an existing source file was never malware scanned', function (): void {
    $this->file->forceFill(['scanner_status' => 'unscanned'])->save();
    config(['ingest.scanner' => 'none']);

    $run = OperationRun::query()->create([
        'operation_type' => ProcessAiAnalysisJob::TYPE,
        'status' => OperationRun::STATUS_QUEUED,
        'requested_by_user_id' => $this->user->id,
        'payload' => ['asset_ids' => [$this->asset->id], 'provider' => 'local', 'cursor' => 0],
        'total_items' => 1,
    ]);

    expect(fn () => (new ProcessAiAnalysisJob($run->id))->handle())
        ->toThrow(RuntimeException::class, 'Activeer eerst ClamAV');

    expect($run->fresh()->error_message)
        ->toContain('niet malwaregescand')
        ->and($run->fresh()->failed_items)->toBe(0);
});

it('rescans an existing unscanned source before sending it to the AI provider', function (): void {
    $this->file->forceFill(['scanner_status' => 'unscanned'])->save();
    config(['ingest.scanner' => 'clamav']);

    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scan')->once()->withArgs(
        fn (string $path): bool => is_file($path) && file_get_contents($path) === 'safe-derived-image-bytes',
    )->andReturn('clean');
    app()->instance(MalwareScanner::class, $scanner);

    Http::fake([
        'http://127.0.0.1:8088/v1/analyze-image' => Http::response([
            'description' => 'Een veilig hergecontroleerde foto.',
            'tags' => ['Veilig'],
            'model_id' => 'local-caption-proof',
            'model_space' => 'local-caption-proof',
            'confidence' => 0.9,
        ]),
    ]);

    $run = OperationRun::query()->create([
        'operation_type' => ProcessAiAnalysisJob::TYPE,
        'status' => OperationRun::STATUS_QUEUED,
        'requested_by_user_id' => $this->user->id,
        'payload' => ['asset_ids' => [$this->asset->id], 'provider' => 'local', 'cursor' => 0],
        'total_items' => 1,
    ]);

    (new ProcessAiAnalysisJob($run->id))->handle();

    expect($run->fresh()->status)->toBe(OperationRun::STATUS_COMPLETED)
        ->and($run->fresh()->processed_items)->toBe(1)
        ->and($this->file->fresh()->scanner_status)->toBe('clean')
        ->and($this->file->fresh()->scanned_at)->not->toBeNull();
});

it('refuses AI batches above the configured limit', function (): void {
    $ids = array_map(fn (int $i): string => '01H00000000000000000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, 11));

    expect(fn () => app(AiDispatchService::class)->dispatchImageAnalysis($ids, 'local', $this->user))
        ->toThrow(ValidationException::class, 'Selecteer 1 tot 10 assets');
});
