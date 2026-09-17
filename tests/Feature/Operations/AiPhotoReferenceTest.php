<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Ai\Jobs\ProcessAiAnalysisJob;
use App\Modules\Ai\Jobs\ProcessAiIndexJob;
use App\Modules\Ai\Models\AiRun;
use App\Modules\Ai\Services\AiAssetBatchService;
use App\Modules\Ai\Services\AiConfigurationService;
use App\Modules\Ai\Services\PgvectorEmbeddingStore;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Models\OperationRunAuditEvent;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    Queue::fake();
    Storage::fake('local');
    // This suite verifies photo-reference workflows; the real vector database
    // contract is exercised separately by PgvectorApplicationAdapterTest.
    $this->partialMock(PgvectorEmbeddingStore::class, function (MockInterface $mock): void {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('requireAvailable')->andReturnNull();
        $mock->shouldReceive('persist')->andReturnNull();
    });
    Http::fake([
        'http://127.0.0.1:8088/v1/analyze-image' => Http::response([
            'description' => 'Een testfoto.',
            'tags' => [],
            'model_id' => 'local-reference-proof',
        ]),
        'http://127.0.0.1:8088/v1/embed-image' => Http::response([
            'embedding' => [0.25, 0.5, 0.75],
            'model_space' => 'reference-proof-space',
            'dimensions' => 3,
        ]),
    ]);
    $this->user = User::query()->create([
        'name' => 'Reference admin',
        'email' => 'ai-reference@example.test',
        'password' => 'test-password',
    ]);
    $this->user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
    $this->asset = Asset::query()->create([
        'id' => '01m2nebgkmzm2r56b7fwq3923t',
        'accession_number' => 'FA-01M2NEBGKMZM2R56B7FWQ3923S',
        'created_by_user_id' => $this->user->id,
    ]);
    $image = imagecreatetruecolor(32, 32);
    ob_start();
    imagejpeg($image);
    $bytes = ob_get_clean();
    imagedestroy($image);
    Storage::disk('local')->put('original.jpg', $bytes);
    Storage::disk('local')->put('preview.jpg', $bytes);
    AssetFile::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'original.jpg',
        'sha256' => hash('sha256', $bytes),
        'byte_size' => strlen($bytes),
        'media_type' => 'image/jpeg',
        'scanner_status' => 'clean',
        'ingest_status' => 'ready_private',
        'is_primary' => true,
        'derivatives' => ['preview1200' => 'preview.jpg'],
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
    ], $this->user);
});

dataset('ai reference surfaces', [
    'analysis' => ['analyze', ProcessAiAnalysisJob::class],
    'index' => ['index', ProcessAiIndexJob::class],
]);

it('resolves visible photo numbers and case variants before dispatch and processes the photo once', function (string $action, string $jobClass): void {
    $this->actingAs($this->user)->post(route('admin.operations.ai.'.$action), [
        'asset_ids' => $this->asset->accession_number.",\n".strtoupper($this->asset->id).' '.$this->asset->id,
        'provider' => 'local',
    ])->assertRedirect(route('admin.operations.runs.index'))->assertSessionHasNoErrors();

    $run = OperationRun::query()->sole();
    expect($run->payload['asset_ids'])->toBe([$this->asset->id])
        ->and($run->payload['asset_id_format'])->toBe('internal-v1')
        ->and($run->total_items)->toBe(1);
    Queue::assertPushed($jobClass, 1);
    Http::assertNothingSent();
    (new $jobClass($run->id))->handle();
    expect($run->fresh()->status)->toBe(OperationRun::STATUS_COMPLETED)
        ->and($run->fresh()->processed_items)->toBe(1)
        ->and(AiRun::query()->sole()->asset_id)->toBe($this->asset->id);
    Http::assertSentCount(1);
    $this->get(route('admin.operations.runs.ai-results', $run))->assertOk()
        ->assertSee(route('admin.assets.show', $this->asset).'#ai-results');
    $response = $this->get(route('admin.assets.show', $this->asset))->assertOk()->assertSee('AI-resultaten');
    if ($action === 'analyze') {
        $response->assertSee('Een testfoto.')->assertSee('local-reference-proof')->assertSee('Te beoordelen');
    } else {
        $response->assertSee('reference-proof-space')->assertSee('Zoekindex');
    }
    Http::assertSentCount(1);
})->with('ai reference surfaces');

it('rejects the entire batch and preserves form input when one reference is invalid', function (string $action): void {
    $input = $this->asset->accession_number.',FA-MISSING';
    $this->actingAs($this->user)->from(route('admin.operations.ai.edit'))
        ->post(route('admin.operations.ai.'.$action), ['asset_ids' => $input, 'provider' => 'local'])
        ->assertRedirect(route('admin.operations.ai.edit'))
        ->assertSessionHasErrors('asset_ids');
    expect(OperationRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
    $this->get(route('admin.operations.ai.edit'))->assertSee($input);
})->with(['analyze', 'index']);

it('refuses deleted photos before queueing and still fails safely when deletion happens after dispatch', function (string $action, string $jobClass): void {
    $this->actingAs($this->user)->post(route('admin.operations.ai.'.$action), [
        'asset_ids' => $this->asset->accession_number,
        'provider' => 'local',
    ])->assertSessionHasNoErrors();
    $run = OperationRun::query()->sole();
    $this->asset->delete();
    expect(fn () => (new $jobClass($run->id))->handle())->toThrow(RuntimeException::class, 'bestaat niet meer');
    expect($run->auditEvents()->sole()->context['asset_id'])->toBe($this->asset->id);
    $this->post(route('admin.operations.ai.'.$action), [
        'asset_ids' => $this->asset->accession_number, 'provider' => 'local',
    ])->assertSessionHasErrors('asset_ids');
    expect(OperationRun::query()->count())->toBe(1);
    Http::assertNothingSent();
})->with('ai reference surfaces');

it('normalizes a failed legacy run on manual retry and preserves its audit history', function (string $action, string $jobClass): void {
    $run = OperationRun::query()->create([
        'operation_type' => $jobClass::TYPE,
        'status' => OperationRun::STATUS_FAILED,
        'requested_by_user_id' => $this->user->id,
        'payload' => ['asset_ids' => [$this->asset->accession_number, $this->asset->id], 'provider' => 'local', 'cursor' => 2],
        'total_items' => 2,
        'failed_items' => 2,
        'error_message' => 'Legacy lookup failed',
    ]);
    $event = OperationRunAuditEvent::query()->create([
        'operation_run_id' => $run->id, 'event_type' => $jobClass::TYPE.'.item_failed',
        'severity' => 'error', 'message' => 'Legacy lookup failed',
    ]);
    $this->actingAs($this->user)->post(route('admin.operations.runs.retry', $run))
        ->assertSessionHasNoErrors()->assertRedirect(route('admin.operations.runs.index'));
    $run->refresh();
    expect($run->payload['asset_ids'])->toBe([$this->asset->id])
        ->and($run->payload['asset_id_format'])->toBe('internal-v1')
        ->and($run->payload['cursor'])->toBe(0)
        ->and($run->total_items)->toBe(1)
        ->and($run->failed_items)->toBe(0)
        ->and($event->fresh()->message)->toBe('Legacy lookup failed')
        ->and($run->auditEvents()->where('event_type', $jobClass::TYPE.'.references_normalized')->sole()->context['references'][0])
        ->toBe(['reference' => $this->asset->accession_number, 'asset_id' => $this->asset->id]);
    (new $jobClass($run->id))->handle();
    expect($run->fresh()->status)->toBe(OperationRun::STATUS_COMPLETED);
})->with('ai reference surfaces');

it('leaves a failed run untouched when references cannot be resolved and never reinterprets a canonical ID', function (string $format): void {
    $payload = ['asset_ids' => ['MISSING'], 'provider' => 'local', 'cursor' => 1];
    if ($format === 'canonical') {
        $this->asset->update(['accession_number' => '01M2NEBGKMZM2R56B7FWQ3923V']);
        $payload['asset_ids'] = [$this->asset->accession_number];
        $payload['asset_id_format'] = 'internal-v1';
    }
    $run = OperationRun::query()->create([
        'operation_type' => ProcessAiAnalysisJob::TYPE, 'status' => OperationRun::STATUS_FAILED,
        'requested_by_user_id' => $this->user->id, 'payload' => $payload,
        'total_items' => 1, 'failed_items' => 1, 'error_message' => 'Original error',
    ]);
    $before = $run->fresh()->getAttributes();
    $this->actingAs($this->user)->post(route('admin.operations.runs.retry', $run))->assertSessionHasErrors('asset_ids');
    expect($run->fresh()->getAttributes())->toBe($before)
        ->and($run->auditEvents()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['legacy', 'canonical']);

it('preserves exact reference precedence and legacy internal ID precedence', function (): void {
    $other = Asset::query()->create([
        'accession_number' => $this->asset->id,
        'created_by_user_id' => $this->user->id,
    ]);
    $batches = app(AiAssetBatchService::class);
    expect($batches->normalize([$this->asset->id], $this->user)['asset_ids'])->toBe([$other->id])
        ->and($batches->normalize([$this->asset->id], $this->user, 'legacy')['asset_ids'])->toBe([$this->asset->id])
        ->and($batches->normalize([$this->asset->id], $this->user, AiAssetBatchService::INTERNAL_FORMAT)['asset_ids'])->toBe([$this->asset->id]);
});

it('supports uppercase stored ULIDs without changing the stored key', function (): void {
    $asset = Asset::query()->create([
        'id' => '01M2NEBGKMZM2R56B7FWQ3923V',
        'accession_number' => 'FA-Uppercase',
        'created_by_user_id' => $this->user->id,
    ]);
    expect(app(AiAssetBatchService::class)->normalize([strtolower($asset->id)], $this->user)['asset_ids'])->toBe([$asset->id]);
});

it('rejects empty oversized and inexact references without queue or HTTP side effects', function (string $case, string $action): void {
    $input = match ($case) {
        'empty' => '',
        'oversized' => implode(',', array_map(fn (int $i): string => 'FA-'.$i, range(1, 11))),
        'partial' => substr($this->asset->accession_number, 0, -1),
        'stripped' => substr($this->asset->accession_number, 3),
        default => strtolower($this->asset->accession_number),
    };
    $this->actingAs($this->user)->post(route('admin.operations.ai.'.$action), [
        'asset_ids' => $input, 'provider' => 'local',
    ])->assertSessionHasErrors('asset_ids');
    expect(OperationRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['empty', 'oversized', 'partial', 'stripped', 'lowercase accession'])->with(['analyze', 'index']);

it('does not permit a volunteer to process another owners photo through references', function (): void {
    $volunteer = User::query()->create([
        'name' => 'Reference volunteer', 'email' => 'reference-volunteer@example.test', 'password' => 'test-password',
    ]);
    $volunteer->roles()->attach(Role::query()->where('key', 'volunteer')->firstOrFail());
    $this->actingAs($volunteer)->post(route('admin.operations.ai.index'), [
        'asset_ids' => $this->asset->accession_number, 'provider' => 'local',
    ])->assertSessionHasErrors('asset_ids');
    $this->post(route('admin.operations.ai.analyze'), [
        'asset_ids' => $this->asset->accession_number, 'provider' => 'local',
    ])->assertForbidden();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect(OperationRun::query()->count())->toBe(0);

    $this->asset->update(['created_by_user_id' => $volunteer->id]);
    $this->post(route('admin.operations.ai.index'), [
        'asset_ids' => $this->asset->accession_number, 'provider' => 'local',
    ])->assertSessionHasNoErrors();
    expect(OperationRun::query()->sole()->payload['asset_ids'])->toBe([$this->asset->id]);
});
