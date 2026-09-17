<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Ai\Jobs\ProcessAiAnalysisJob;
use App\Modules\Ai\Models\AiSuggestion;
use App\Modules\Ai\Services\AiConfigurationService;
use App\Modules\Ai\Services\AiDispatchService;
use App\Modules\Ai\Services\AiProviderConfigService;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Models\OperationRunAuditEvent;
use App\Modules\ArchiveOperations\Services\OperationRunService;
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
        ->and(AiSuggestion::query()->where('asset_id', $this->asset->id)->where('review_status', AiSuggestion::REVIEW_PENDING)->count())->toBe(3)
        ->and(OperationRunAuditEvent::query()->where('operation_run_id', $run->id)->where('event_type', 'ai.analysis.item_succeeded')->count())->toBe(1);
});

it('processes OpenAI analysis through the worker with the safe review chain and no live provider calls', function (): void {
    app(AiProviderConfigService::class)->update('openai', [
        'enabled' => true,
        'vision_model' => 'gpt-4.1-mini',
    ], $this->user);
    app(AiConfigurationService::class)->update([
        'global_enabled' => '1',
        'image_analysis_enabled' => '1',
        'embeddings_enabled' => '1',
        'local_provider_enabled' => '1',
        'local_endpoint' => 'http://127.0.0.1:8088',
        'external_processing_allowed' => '0',
        'image_analysis_native_consent' => '1',
        'image_analysis_provider' => 'openai',
        'max_assets_per_batch' => 10,
        'derivative_max_pixels' => 512,
        'request_timeout_seconds' => 15,
    ], $this->user);
    app(AiProviderConfigService::class)->update('openai', [
        'enabled' => true,
        'vision_model' => 'gpt-4.1-mini',
        'cost_cents_per_image' => 1,
        'monthly_budget_cents' => 100,
    ], $this->user);
    app(AiProviderConfigService::class)->setApiKey('openai', 'test-openai-secret', $this->user);
    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::response([
            'model' => 'gpt-4.1-mini-2026-09-17',
            'choices' => [[
                'message' => [
                    'content' => '{"description":"Een veilige OpenAI fixturebeschrijving.","tags":["Archief","Controle"],"confidence":0.91}',
                ],
            ]],
        ]),
    ]);

    $run = OperationRun::query()->create([
        'operation_type' => ProcessAiAnalysisJob::TYPE,
        'status' => OperationRun::STATUS_QUEUED,
        'requested_by_user_id' => $this->user->id,
        'payload' => ['asset_ids' => [$this->asset->id], 'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'cursor' => 0],
        'total_items' => 1,
    ]);

    (new ProcessAiAnalysisJob($run->id))->handle();

    Http::assertSent(function ($request): bool {
        $payload = $request->data();
        $imageUrl = $payload['messages'][0]['content'][1]['image_url']['url'] ?? null;

        return $request->url() === 'https://api.openai.com/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer test-openai-secret')
            && ($payload['model'] ?? null) === 'gpt-4.1-mini'
            && ($payload['response_format']['type'] ?? null) === 'json_object'
            && is_string($imageUrl)
            && str_starts_with($imageUrl, 'data:image/jpeg;base64,')
            && ! str_contains($imageUrl, 'safe-derived-image-bytes');
    });

    $aiRun = $this->asset->aiRuns()->firstOrFail();
    $auditPayload = json_encode(OperationRunAuditEvent::query()->where('operation_run_id', $run->id)->get()->toArray(), JSON_THROW_ON_ERROR);
    expect($run->fresh()->status)->toBe(OperationRun::STATUS_COMPLETED)
        ->and($this->asset->fresh()->description)->toBeNull()
        ->and($aiRun->provider_kind)->toBe('openai')
        ->and($aiRun->provider_name)->toBe('native-openai')
        ->and($aiRun->model_id)->toBe('gpt-4.1-mini')
        ->and($aiRun->model_version)->toBe('gpt-4.1-mini-2026-09-17')
        ->and($aiRun->input_contract)->toMatchArray([
            'metadata_stripped' => true,
            'automatic_metadata_write' => false,
        ])
        ->and(AiSuggestion::query()->where('asset_id', $this->asset->id)->where('review_status', AiSuggestion::REVIEW_PENDING)->pluck('value')->all())
        ->toBe(['Een veilige OpenAI fixturebeschrijving.', 'archief', 'controle'])
        ->and($auditPayload)->not->toContain('test-openai-secret')
        ->and($auditPayload)->not->toContain('data:image/jpeg');
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

    $event = OperationRunAuditEvent::query()->where('operation_run_id', $run->id)->firstOrFail();
    expect($event->event_type)->toBe('ai.analysis.item_failed')
        ->and($event->severity)->toBe('error')
        ->and($event->message)->toContain('Activeer eerst ClamAV')
        ->and($event->context['provider'])->toBe('local')
        ->and($event->context['scanner_status'])->toBe('unscanned')
        ->and($event->context)->not->toHaveKeys(['api_key', 'image_base64', 'response']);
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

it('cancels a queued AI run after the emergency stop is enabled without contacting a provider', function (): void {
    Http::preventStrayRequests();
    $run = OperationRun::query()->create([
        'operation_type' => ProcessAiAnalysisJob::TYPE,
        'status' => OperationRun::STATUS_QUEUED,
        'requested_by_user_id' => $this->user->id,
        'payload' => ['asset_ids' => [$this->asset->id], 'provider' => 'local', 'cursor' => 0],
        'total_items' => 1,
    ]);

    $settings = app(AiConfigurationService::class)->effective();
    app(AiConfigurationService::class)->update(array_merge($settings, ['emergency_stop' => true]), $this->user);

    (new ProcessAiAnalysisJob($run->id))->handle();

    expect($run->fresh()->status)->toBe(OperationRun::STATUS_CANCELLED)
        ->and($run->fresh()->processed_items)->toBe(0)
        ->and($run->fresh()->error_message)->toContain('AI-taak geannuleerd')
        ->and(AiSuggestion::query()->count())->toBe(0);
});

it('preserves processed and failed counters for items already completed before a mid-chunk cancellation, without overwriting the cancelled status or claim', function (): void {
    $calls = 0;
    Http::fake([
        'http://127.0.0.1:8088/v1/analyze-image' => function () use (&$calls) {
            $calls++;
            if ($calls > 1) {
                throw new RuntimeException('No second provider request expected once the run is cancelled mid-chunk.');
            }

            // Simulate the capability going unavailable while this first item is
            // still in flight, so the second item is never attempted.
            $settings = app(AiConfigurationService::class)->effective();
            app(AiConfigurationService::class)->update(array_merge($settings, ['emergency_stop' => true]), $this->user);

            return Http::response([
                'description' => 'Eerste veilige testfoto.',
                'tags' => ['Eerste'],
                'model_id' => 'local-caption-proof',
                'model_space' => 'local-caption-proof',
                'confidence' => 0.8,
            ]);
        },
    ]);

    $secondAsset = Asset::query()->create([
        'accession_number' => 'AI-ASYNC-SECOND-'.(string) str()->ulid(),
        'title' => 'Async AI contract second item',
        'created_by_user_id' => $this->user->id,
    ])->fresh();
    Storage::disk('local')->put('originals/async-ai-second.jpg', 'safe-derived-image-bytes-second');
    $secondImage = imagecreatetruecolor(800, 600);
    imagefill($secondImage, 0, 0, 0xCCBBAA);
    ob_start();
    imagejpeg($secondImage, null, 90);
    $secondDerivative = ob_get_clean();
    imagedestroy($secondImage);
    Storage::disk('local')->put('derivatives/async-ai-second-preview-1200.jpg', $secondDerivative);
    $secondFile = AssetFile::query()->create([
        'asset_id' => $secondAsset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/async-ai-second.jpg',
        'sha256' => hash('sha256', 'safe-derived-image-bytes-second'),
        'media_type' => 'image/jpeg',
        'byte_size' => 12345,
        'ingest_status' => 'ready_private',
        'scanner_status' => 'clean',
        'derivatives' => ['preview1200' => 'derivatives/async-ai-second-preview-1200.jpg'],
        'is_primary' => true,
    ]);

    $run = OperationRun::query()->create([
        'operation_type' => ProcessAiAnalysisJob::TYPE,
        'status' => OperationRun::STATUS_QUEUED,
        'requested_by_user_id' => $this->user->id,
        'payload' => ['asset_ids' => [$this->asset->id, $secondAsset->id], 'provider' => 'local', 'cursor' => 0],
        'total_items' => 2,
    ]);

    (new ProcessAiAnalysisJob($run->id))->handle();

    $run->refresh();
    expect($run->status)->toBe(OperationRun::STATUS_CANCELLED)
        ->and($run->processed_items)->toBe(1)
        ->and($run->failed_items)->toBe(0)
        ->and($run->claim_token)->toBeNull()
        ->and($run->error_message)->toContain('AI-taak geannuleerd')
        ->and($calls)->toBe(1)
        ->and(AiSuggestion::query()->where('asset_id', $this->asset->id)->count())->toBeGreaterThan(0)
        ->and(AiSuggestion::query()->where('asset_id', $secondAsset->id)->count())->toBe(0);
});

it('restarts a legacy failed AI run from the first item with clean counters', function (): void {
    Queue::fake();
    $run = OperationRun::query()->create([
        'operation_type' => ProcessAiAnalysisJob::TYPE,
        'status' => OperationRun::STATUS_FAILED,
        'requested_by_user_id' => $this->user->id,
        'payload' => ['asset_ids' => [$this->asset->id], 'provider' => 'local', 'cursor' => 1],
        'result' => ['provider' => 'local'],
        'total_items' => 1,
        'processed_items' => 0,
        'failed_items' => 1,
        'error_message' => 'Oude fout',
        'finished_at' => now(),
    ]);

    app(OperationRunService::class)->retryRun($run, ProcessAiAnalysisJob::class);

    $run->refresh();
    expect($run->status)->toBe(OperationRun::STATUS_QUEUED)
        ->and($run->payload['cursor'])->toBe(0)
        ->and($run->processed_items)->toBe(0)
        ->and($run->failed_items)->toBe(0)
        ->and($run->result)->toBeNull()
        ->and($run->error_message)->toBeNull();
    Queue::assertPushed(ProcessAiAnalysisJob::class);
});
