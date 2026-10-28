<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Ai\Jobs\ProcessAiAnalysisJob;
use App\Modules\Ai\Models\AiRun;
use App\Modules\Ai\Services\AiAutoAnalysisService;
use App\Modules\Ai\Services\AiConfigurationService;
use App\Modules\Ai\Services\AiProviderConfigService;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Models\OperationRunAuditEvent;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function autoReadyAsset(string $createdAt = 'now'): Asset
{
    $asset = Asset::query()->create([
        'accession_number' => 'AI-AUTO-'.(string) str()->ulid(),
        'title' => 'Auto analysis photo',
        'created_by_user_id' => test()->user->id,
    ])->fresh();
    $asset->forceFill(['created_at' => $createdAt === 'now' ? now()->addMinute() : now()->subDays(3)])->save();
    $bytes = 'original-'.(string) str()->ulid();
    Storage::disk('local')->put("originals/{$asset->id}.jpg", $bytes);
    $image = imagecreatetruecolor(64, 48);
    ob_start();
    imagejpeg($image, null, 80);
    Storage::disk('local')->put("derivatives/{$asset->id}.jpg", (string) ob_get_clean());
    imagedestroy($image);
    AssetFile::query()->create([
        'asset_id' => $asset->id,
        'storage_disk' => 'local',
        'storage_key' => "originals/{$asset->id}.jpg",
        'sha256' => hash('sha256', $bytes),
        'media_type' => 'image/jpeg',
        'byte_size' => strlen($bytes),
        'ingest_status' => 'ready_private',
        'scanner_status' => 'clean',
        'derivatives' => ['preview1200' => "derivatives/{$asset->id}.jpg"],
        'is_primary' => true,
    ]);

    return $asset->fresh();
}

function autoEnable(bool $backfill = false): void
{
    app(AiAutoAnalysisService::class)->update(['enabled' => true, 'backfill' => $backfill], test()->user);
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    Storage::fake('local');
    config(['ai.auto_analysis.min_seconds_between_requests' => 0]);
    $this->user = User::query()->create([
        'name' => 'AI Auto Admin',
        'email' => 'ai-auto@example.test',
        'password' => Hash::make('secret12345'),
    ]);
    $this->user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
    app(AiConfigurationService::class)->update([
        'global_enabled' => '1',
        'image_analysis_enabled' => '1',
        'local_provider_enabled' => '1',
        'local_endpoint' => 'http://127.0.0.1:8088',
        'image_analysis_provider' => 'local',
        'max_assets_per_batch' => 10,
        'derivative_max_pixels' => 512,
        'request_timeout_seconds' => 15,
    ], $this->user);
});

it('does nothing while automatic analysis is off', function (): void {
    Queue::fake();
    autoReadyAsset();

    expect(app(AiAutoAnalysisService::class)->sweep())->toMatchArray(['status' => 'disabled', 'dispatched' => 0]);
    expect(OperationRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses to switch on while AI is not ready', function (): void {
    app(AiConfigurationService::class)->update(['global_enabled' => '0', 'image_analysis_enabled' => '1', 'image_analysis_provider' => 'local', 'max_assets_per_batch' => 10, 'derivative_max_pixels' => 512, 'request_timeout_seconds' => 15], $this->user);

    expect(fn () => autoEnable())->toThrow(ValidationException::class);
});

it('refuses the external provider because its spend is not metered', function (): void {
    expect(app(AiAutoAnalysisService::class)->blocker(['active' => true, 'image_analysis_enabled' => true, 'image_analysis_ready' => true, 'image_analysis_provider' => 'external']))->toBe('provider');
});

it('analyses only photos added after switching on unless the backfill is chosen', function (): void {
    Queue::fake();
    $old = autoReadyAsset('old');
    autoEnable();
    $new = autoReadyAsset();

    $result = app(AiAutoAnalysisService::class)->sweep();

    $run = OperationRun::query()->findOrFail($result['run_id']);
    expect($result['status'])->toBe('dispatched')
        ->and($run->payload['asset_ids'])->toBe([$new->id])
        ->and($run->payload['source'])->toBe('auto')
        ->and($run->requested_by_user_id)->toBe($this->user->id);

    $run->forceFill(['status' => OperationRun::STATUS_COMPLETED])->save();
    autoEnable(backfill: true);
    $again = app(AiAutoAnalysisService::class)->sweep();
    expect(OperationRun::query()->findOrFail($again['run_id'])->payload['asset_ids'])->toContain($old->id);
});

it('leaves out photos already analysed for the provider and photos that recently failed', function (): void {
    Queue::fake();
    $analysed = autoReadyAsset('old');
    $failed = autoReadyAsset('old');
    $waiting = autoReadyAsset('old');
    $file = $analysed->files()->firstOrFail();
    AiRun::query()->create([
        'run_type' => AiRun::TYPE_IMAGE_ANALYSIS, 'status' => AiRun::STATUS_SUCCEEDED,
        'asset_id' => $analysed->id, 'asset_file_id' => $file->id,
        'source_asset_lock_version' => $analysed->lock_version, 'source_file_sha256' => $file->sha256,
        'provider_kind' => 'local', 'provider_name' => 'owned-http', 'model_id' => 'proof',
        'idempotency_key' => 'k-'.$file->id, 'input_contract' => ['metadata_stripped' => true],
    ]);
    $run = OperationRun::query()->create(['operation_type' => ProcessAiAnalysisJob::TYPE, 'status' => OperationRun::STATUS_FAILED, 'requested_by_user_id' => $this->user->id, 'payload' => [], 'total_items' => 1]);
    OperationRunAuditEvent::query()->create(['operation_run_id' => $run->id, 'asset_id' => $failed->id, 'event_type' => 'ai.analysis.item_failed', 'severity' => 'error', 'message' => 'boom', 'context' => []]);
    autoEnable(backfill: true);

    $result = app(AiAutoAnalysisService::class)->sweep();

    expect(OperationRun::query()->findOrFail($result['run_id'])->payload['asset_ids'])->toBe([$waiting->id]);
});

it('runs one analysis at a time and respects the daily limit', function (): void {
    Queue::fake();
    autoEnable(backfill: true);
    autoReadyAsset('old');
    $first = app(AiAutoAnalysisService::class)->sweep();
    expect($first['status'])->toBe('dispatched');

    expect(app(AiAutoAnalysisService::class)->sweep()['status'])->toBe('busy');

    OperationRun::query()->findOrFail($first['run_id'])->forceFill(['status' => OperationRun::STATUS_COMPLETED])->save();
    config(['ai.auto_analysis.daily_max' => 0]);
    expect(app(AiAutoAnalysisService::class)->sweep()['status'])->toBe('daily_limit');
});

it('stops when the emergency stop is on or the enabling administrator may no longer manage', function (): void {
    Queue::fake();
    autoEnable(backfill: true);
    autoReadyAsset('old');

    $this->user->forceFill(['is_active' => false])->save();
    expect(app(AiAutoAnalysisService::class)->sweep()['status'])->toBe('actor');

    $this->user->forceFill(['is_active' => true])->save();
    app(AiConfigurationService::class)->update(['global_enabled' => '1', 'emergency_stop' => '1', 'image_analysis_enabled' => '1', 'local_provider_enabled' => '1', 'local_endpoint' => 'http://127.0.0.1:8088', 'image_analysis_provider' => 'local', 'max_assets_per_batch' => 10, 'derivative_max_pixels' => 512, 'request_timeout_seconds' => 15], $this->user);
    expect(app(AiAutoAnalysisService::class)->sweep()['status'])->toBe('not_ready');
    Queue::assertNothingPushed();
});

it('sizes a batch to the remaining monthly budget of a native provider', function (): void {
    Queue::fake();
    app(AiProviderConfigService::class)->update('openai', ['enabled' => true, 'vision_model' => 'gpt-4.1-mini', 'cost_cents_per_image' => 10, 'monthly_budget_cents' => 25], $this->user);
    app(AiProviderConfigService::class)->setApiKey('openai', 'test-openai-secret', $this->user);
    app(AiConfigurationService::class)->update([
        'global_enabled' => '1', 'image_analysis_enabled' => '1', 'image_analysis_provider' => 'openai', 'image_analysis_native_consent' => '1',
        'local_provider_enabled' => '1', 'local_endpoint' => 'http://127.0.0.1:8088', 'max_assets_per_batch' => 10, 'derivative_max_pixels' => 512, 'request_timeout_seconds' => 15,
    ], $this->user);
    autoEnable(backfill: true);
    foreach (range(1, 4) as $unused) {
        autoReadyAsset('old');
    }

    $result = app(AiAutoAnalysisService::class)->sweep();

    expect($result['dispatched'])->toBe(2);
    $status = app(AiAutoAnalysisService::class)->status();
    expect($status['cost_cents'])->toBe(10)->and($status['backlog'])->toBe(4)->and($status['estimated_cents'])->toBe(40)->and($status['budget_remaining'])->toBe(25);

    OperationRun::query()->findOrFail($result['run_id'])->forceFill(['status' => OperationRun::STATUS_COMPLETED])->save();
    app(AiProviderConfigService::class)->update('openai', ['enabled' => true, 'vision_model' => 'gpt-4.1-mini', 'cost_cents_per_image' => 30, 'monthly_budget_cents' => 25], $this->user);
    expect(app(AiAutoAnalysisService::class)->sweep()['status'])->toBe('budget');
});

it('stops starting photos at the chunk deadline and continues in the next chunk', function (): void {
    Queue::fake();
    config(['ai.auto_analysis.chunk_deadline_seconds' => 0]);
    Http::fake(['http://127.0.0.1:8088/v1/analyze-image' => Http::response(['description' => 'Een dorpsplein.', 'tags' => ['dorpsplein'], 'model_id' => 'proof', 'confidence' => 0.9])]);
    $a = autoReadyAsset('old');
    $b = autoReadyAsset('old');
    $run = OperationRun::query()->create([
        'operation_type' => ProcessAiAnalysisJob::TYPE, 'status' => OperationRun::STATUS_QUEUED, 'requested_by_user_id' => $this->user->id,
        'payload' => ['asset_ids' => [$a->id, $b->id], 'provider' => 'local', 'cursor' => 0, 'source' => 'auto'], 'total_items' => 2,
    ]);

    (new ProcessAiAnalysisJob($run->id))->handle();

    $run->refresh();
    expect($run->status)->toBe(OperationRun::STATUS_QUEUED)->and($run->processed_items)->toBe(1)->and($run->payload['cursor'])->toBe(1);
    Queue::assertPushed(ProcessAiAnalysisJob::class);
});

it('reports an archive estimate without queueing and demands a cost ceiling for paid calls', function (): void {
    Queue::fake();
    autoReadyAsset('old');
    autoReadyAsset('old');

    $this->artisan('ai:analyse-archive', ['--dry-run' => true, '--limit' => 1])->assertExitCode(0);
    expect(OperationRun::query()->count())->toBe(0);

    $report = app(AiAutoAnalysisService::class)->archiveRun(10, null, null, true);
    expect($report['backlog'])->toBe(2)->and($report['selected'])->toBe(2)->and($report['estimated_cost_cents'])->toBe(0);

    app(AiProviderConfigService::class)->update('openai', ['enabled' => true, 'vision_model' => 'gpt-4.1-mini', 'cost_cents_per_image' => 10, 'monthly_budget_cents' => 100], $this->user);
    app(AiProviderConfigService::class)->setApiKey('openai', 'test-openai-secret', $this->user);
    app(AiConfigurationService::class)->update([
        'global_enabled' => '1', 'image_analysis_enabled' => '1', 'image_analysis_provider' => 'openai', 'image_analysis_native_consent' => '1',
        'local_provider_enabled' => '1', 'local_endpoint' => 'http://127.0.0.1:8088', 'max_assets_per_batch' => 10, 'derivative_max_pixels' => 512, 'request_timeout_seconds' => 15,
    ], $this->user);

    expect(fn () => app(AiAutoAnalysisService::class)->archiveRun(10, null, $this->user, false))->toThrow(ValidationException::class);
    expect(fn () => app(AiAutoAnalysisService::class)->archiveRun(10, 5, $this->user, false))->toThrow(ValidationException::class);
    expect(OperationRun::query()->count())->toBe(0);

    $queued = app(AiAutoAnalysisService::class)->archiveRun(10, 20, $this->user, false);
    expect($queued['runs'])->toHaveCount(1)
        ->and(OperationRun::query()->findOrFail($queued['runs'][0])->payload['source'])->toBe('archive');
});

it('lets an administrator switch automatic analysis on only with consent', function (): void {
    $this->actingAs($this->user)->get(route('admin.operations.ai.edit'))
        ->assertOk()
        ->assertSee(__('ai.auto.title'));

    $this->post(route('admin.operations.ai.automatic.update'), ['auto_analysis_enabled' => 1])
        ->assertSessionHasErrors('auto_analysis_consent');
    expect(app(AiConfigurationService::class)->effective()['auto_analysis_enabled'])->toBe(false);

    $this->post(route('admin.operations.ai.automatic.update'), ['auto_analysis_enabled' => 1, 'auto_analysis_consent' => 1])
        ->assertRedirect(route('admin.operations.ai.edit'));
    $settings = app(AiConfigurationService::class)->effective();
    expect($settings['auto_analysis_enabled'])->toBe(true)->and($settings['auto_analysis_user_id'])->toBe($this->user->id);

    $this->post(route('admin.operations.ai.automatic.update'), [])->assertRedirect(route('admin.operations.ai.edit'));
    expect(app(AiConfigurationService::class)->effective()['auto_analysis_enabled'])->toBe(false);
});

it('keeps the settings of automatic analysis to administrators', function (): void {
    $viewer = User::query()->create(['name' => 'Viewer', 'email' => 'viewer-auto@example.test', 'password' => Hash::make('secret12345')]);
    $viewer->roles()->attach(Role::query()->where('key', 'viewer')->firstOrFail());

    $this->actingAs($viewer)->post(route('admin.operations.ai.automatic.update'), ['auto_analysis_enabled' => 1, 'auto_analysis_consent' => 1])->assertForbidden();
});

it('registers the sweep on the scheduler and prints its outcome', function (): void {
    expect(collect(app(Schedule::class)->events())->contains(fn ($event): bool => str_contains((string) $event->command, 'ai:sweep')))->toBeTrue();

    Artisan::call('ai:sweep');
    expect(json_decode(trim(Artisan::output()), true))->toMatchArray(['status' => 'disabled']);
});
