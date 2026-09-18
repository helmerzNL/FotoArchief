<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Ai\Models\AiEmbedding;
use App\Modules\Ai\Models\AiEmbeddingGeneration;
use App\Modules\Ai\Services\AiConfigurationService;
use App\Modules\Ai\Services\AiDispatchService;
use App\Modules\Ai\Services\AiIndexWorkbench;
use App\Modules\Ai\Services\AiSemanticSearchService;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\Collection;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::query()->create(['name' => 'Index', 'email' => 'index@example.test', 'password' => 'unused']);
    $this->admin->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
    $this->viewer = User::query()->create(['name' => 'Viewer', 'email' => 'index-view@example.test', 'password' => 'unused']);
    $this->viewer->roles()->attach(Role::query()->where('key', 'viewer')->firstOrFail());
    $this->collection = Collection::query()->create(['title' => 'Repair collection', 'slug' => 'repair-collection']);
    $this->asset = Asset::query()->create(['title' => 'Index photo', 'accession_number' => 'INDEX-WB', 'created_by_user_id' => $this->admin->id])->fresh();
    $this->collection->assets()->attach($this->asset);
    $this->file = AssetFile::query()->create([
        'asset_id' => $this->asset->id, 'storage_disk' => 'local', 'storage_key' => 'index.jpg',
        'sha256' => str_repeat('a', 64), 'byte_size' => 100, 'media_type' => 'image/jpeg', 'is_primary' => true,
        'scanner_status' => 'clean', 'ingest_status' => 'ready_private',
    ]);
    Http::preventStrayRequests();
});

it('reports missing excluded stale and failed coverage without invoking a provider', function (): void {
    $service = app(AiIndexWorkbench::class);
    expect($service->coverage($this->admin, $this->collection->id)->first()->coverage_status)->toBe('missing');
    $this->file->update(['scanner_status' => 'infected']);
    expect($service->coverage($this->admin, $this->collection->id)->first()->coverage_status)->toBe('excluded');
    $this->file->update(['scanner_status' => 'clean']);
    $provider = (string) app(AiConfigurationService::class)->effective()['embeddings_provider'];
    $generation = AiEmbeddingGeneration::query()->create([
        'provider_kind' => $provider, 'provider_name' => 'fixture', 'model_id' => 'fixture', 'model_space' => 'fixture',
        'dimensions' => 2, 'distance_metric' => 'cosine', 'vector_backend' => 'database_json', 'status' => 'retired',
    ]);
    AiEmbedding::query()->create([
        'ai_embedding_generation_id' => $generation->id, 'asset_id' => $this->asset->id, 'asset_file_id' => $this->file->id,
        'source_asset_lock_version' => 1, 'source_file_sha256' => $this->file->sha256, 'embedding' => [1, 0], 'indexed_at' => now(),
    ]);
    expect($service->coverage($this->admin, $this->collection->id)->first()->coverage_status)->toBe('stale');
    $run = OperationRun::query()->create(['operation_type' => 'ai.index', 'status' => 'failed', 'payload' => ['provider' => $provider]]);
    $run->auditEvents()->create(['asset_id' => $this->asset->id, 'event_type' => 'ai.index.item_failed', 'severity' => 'error', 'message' => 'Index unavailable']);
    expect($service->coverage($this->admin, $this->collection->id)->first()->coverage_status)->toBe('failed');
    $this->actingAs($this->admin)->get('/admin/operations/ai/index-workbench?collection='.$this->collection->id)
        ->assertOk()->assertSee('Indexdekking')->assertSee('INDEX-WB');
    $this->actingAs($this->viewer)->get('/admin/operations/ai/index-workbench')->assertForbidden();
    Http::assertNothingSent();
});

it('repairs only selected missing items inside the collection through the existing dispatcher', function (): void {
    $child = OperationRun::query()->create(['operation_type' => 'ai.index', 'status' => 'queued']);
    $this->mock(AiDispatchService::class)->shouldReceive('dispatchEmbeddingIndex')->once()
        ->with([$this->asset->accession_number], Mockery::type('string'), Mockery::on(fn ($user) => $user->id === $this->admin->id))->andReturn($child);
    $this->actingAs($this->admin)->post('/admin/operations/ai/index-workbench/repair', [
        'collection' => $this->collection->id, 'selected' => [$this->asset->id], 'confirm' => '1',
        'configuration' => app(AiIndexWorkbench::class)->configurationFingerprint(),
    ])->assertRedirect('/admin/operations/runs/'.$child->id);
});

it('rejects foreign selections changed heads and excluded items before dispatch', function (): void {
    Queue::fake();
    $foreign = Collection::query()->create(['title' => 'Foreign', 'slug' => 'foreign']);
    $data = ['collection' => $foreign->id, 'selected' => [$this->asset->id], 'confirm' => '1', 'configuration' => app(AiIndexWorkbench::class)->configurationFingerprint()];
    $this->actingAs($this->admin)->post('/admin/operations/ai/index-workbench/repair', $data)->assertSessionHasErrors('selected');
    $data['collection'] = $this->collection->id;
    $data['head'] = (string) str()->ulid();
    $this->post('/admin/operations/ai/index-workbench/repair', $data)->assertSessionHasErrors('selected');
    unset($data['head']);
    $data['configuration'] = str_repeat('0', 64);
    $this->post('/admin/operations/ai/index-workbench/repair', $data)->assertSessionHasErrors('selected');
    $data['configuration'] = app(AiIndexWorkbench::class)->configurationFingerprint();
    $this->file->update(['scanner_status' => 'infected']);
    $this->post('/admin/operations/ai/index-workbench/repair', $data)->assertSessionHasErrors('selected');
    Queue::assertNothingPushed();
});

it('provides explicit text search when AI is off without an external fallback', function (): void {
    $this->actingAs($this->admin)->get('/admin/operations/ai/search?mode=text&q=hello&collection='.$this->collection->id)
        ->assertRedirect(route('admin.assets.index', ['q' => 'hello', 'collection_id' => $this->collection->id]));
    $this->get('/admin/operations/ai/search')->assertOk()->assertSee('Geen automatische fallback')->assertSee('Vul een zoekopdracht');
    Http::assertNothingSent();
});

it('reports and audits a refused generation switch instead of returning a server error', function (): void {
    $run = OperationRun::query()->create(['operation_type' => 'ai.index', 'status' => 'failed', 'requested_by_user_id' => $this->admin->id]);
    $generation = AiEmbeddingGeneration::query()->create([
        'operation_run_id' => $run->id, 'provider_kind' => 'local', 'provider_name' => 'fixture', 'model_id' => 'fixture',
        'model_space' => 'fixture', 'dimensions' => 2, 'distance_metric' => 'cosine', 'vector_backend' => 'pgvector', 'status' => 'building',
    ]);
    $path = '/admin/operations/ai/index-workbench/'.$generation->id.'/activate';
    $this->actingAs($this->admin)->post($path, ['confirm' => '1'])->assertSessionHasErrors('generation');
    expect($generation->fresh()->status)->toBe('building')
        ->and($run->auditEvents()->where('event_type', 'ai.index.generation_failed')->count())->toBe(1);
    $this->actingAs($this->viewer)->post($path, ['confirm' => '1'])->assertForbidden();
});

it('binds relevance labels to actual signed results user scope and expiry', function (): void {
    $this->mock(AiSemanticSearchService::class)->shouldReceive('searchAdmin')->once()
        ->with('market', Mockery::type('string'), Mockery::type(User::class), 10, $this->collection->id)
        ->andReturn([['asset_id' => $this->asset->id, 'accession_number' => 'INDEX-WB', 'title' => 'Index photo', 'score' => 0.9, 'model_space' => 'fixture']]);
    $response = $this->actingAs($this->admin)->get('/admin/operations/ai/search?q=market&collection='.$this->collection->id)->assertOk();
    preg_match('/name="receipt" value="([^"]+)"/', $response->getContent(), $match);
    $receipt = html_entity_decode($match[1]);
    $this->post('/admin/operations/ai/relevance', ['receipt' => $receipt, 'grade' => 2])->assertRedirect('/admin/operations/ai/search');
    $this->post('/admin/operations/ai/relevance', ['receipt' => $receipt, 'grade' => 1])->assertRedirect();
    expect(DB::table('ai_relevance_labels')->count())->toBe(1)->and(DB::table('ai_relevance_labels')->value('grade'))->toBe(1);
    $export = $this->get('/admin/operations/ai/relevance')->assertOk()->streamedContent();
    expect(json_decode(trim($export), true)['query'])->toBe('market');
    $decoded = json_decode(Crypt::decryptString($receipt), true);
    $decoded['expires'] = time() - 1;
    $expired = Crypt::encryptString(json_encode($decoded));
    $this->post('/admin/operations/ai/relevance', ['receipt' => $expired, 'grade' => 2])->assertSessionHasErrors('receipt');
    $this->post('/admin/operations/ai/relevance', ['receipt' => 'forged', 'grade' => 2])->assertSessionHasErrors('receipt');
    $this->actingAs($this->viewer)->post('/admin/operations/ai/relevance', ['receipt' => $receipt, 'grade' => 2])->assertForbidden();
    $this->get('/admin/operations/ai/relevance')->assertForbidden();
});
