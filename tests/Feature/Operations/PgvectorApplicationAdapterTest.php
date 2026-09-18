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
use App\Modules\Ai\Services\AiIndexGenerationService;
use App\Modules\Ai\Services\AiIndexWorkbench;
use App\Modules\Ai\Services\AiSemanticSearchService;
use App\Modules\Ai\Services\PgvectorEmbeddingStore;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Services\OperationRunService;
use App\Modules\ArchiveOperations\Services\OperationWorkbenchService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\Collection;
use App\Modules\Publication\Models\Publication;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;

function pgvectorApplicationDatabase(): ?string
{
    $database = (string) getenv('FOTOARCHIEF_TEST_PGVECTOR_DATABASE');

    if ($database !== '' && ! str_ends_with($database, '_pgvector_test')) {
        throw new RuntimeException('Refusing non-disposable PostgreSQL database: test target must end _pgvector_test.');
    }

    return $database !== '' ? $database : null;
}

beforeEach(function (): void {
    $database = pgvectorApplicationDatabase();
    if ($database === null) {
        $this->markTestSkipped('Requires explicitly configured isolated PostgreSQL/pgvector database ending _pgvector_test.');
    }

    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql.host' => getenv('FOTOARCHIEF_TEST_PGVECTOR_HOST') ?: '127.0.0.1',
        'database.connections.pgsql.port' => getenv('FOTOARCHIEF_TEST_PGVECTOR_PORT') ?: 5432,
        'database.connections.pgsql.database' => $database,
        'database.connections.pgsql.username' => getenv('FOTOARCHIEF_TEST_PGVECTOR_USER') ?: 'fotoarchief',
        'database.connections.pgsql.password' => getenv('FOTOARCHIEF_TEST_PGVECTOR_PASSWORD') ?: '',
    ]);
    DB::purge('pgsql');
    DB::connection('pgsql')->statement('DROP SCHEMA IF EXISTS public CASCADE');
    DB::connection('pgsql')->statement('CREATE SCHEMA public');
    DB::connection('pgsql')->statement('CREATE EXTENSION IF NOT EXISTS vector');
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function (): void {
    DB::disconnect('pgsql');
});

it('persists and searches application embeddings through pgvector without JSON fallback', function (): void {
    $store = app(PgvectorEmbeddingStore::class);
    expect($store->available())->toBeTrue();

    $user = User::query()->create([
        'name' => 'pgvector acceptance',
        'email' => 'pgvector@example.test',
        'password' => Hash::make('not-a-production-password'),
    ]);
    $asset = Asset::query()->create([
        'accession_number' => 'PGVECTOR-001',
        'created_by_user_id' => $user->id,
        'lock_version' => 1,
    ]);
    $file = AssetFile::query()->create([
        'asset_id' => $asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/pgvector-001.jpg',
        'sha256' => str_repeat('a', 64),
        'media_type' => 'image/jpeg',
        'byte_size' => 1,
        'ingest_status' => 'ready_private',
        'scanner_status' => 'clean',
        'is_primary' => true,
    ]);
    $generation = AiEmbeddingGeneration::query()->create([
        'provider_kind' => 'local',
        'provider_name' => 'owned-http',
        'model_id' => 'test-clip',
        'model_space' => 'test-clip:3:cosine',
        'dimensions' => 3,
        'distance_metric' => 'cosine',
        'vector_backend' => 'pgvector',
        'status' => AiEmbeddingGeneration::STATUS_ACTIVE,
        'activated_at' => now(),
    ]);
    $embedding = AiEmbedding::query()->create([
        'ai_embedding_generation_id' => $generation->id,
        'asset_id' => $asset->id,
        'asset_file_id' => $file->id,
        'source_asset_lock_version' => $asset->lock_version,
        'source_file_sha256' => $file->sha256,
        'indexed_at' => now(),
    ]);

    $store->persist($embedding, [1.0, 0.0, 0.0]);

    $eligible = Asset::query()->select('assets.id')->whereKey($asset->id)->limit(1)->offset(0)->toBase();
    $scopeSql = $eligible->toSql();
    expect($embedding->fresh()->embedding)->toBeNull()
        ->and($store->nearest($generation, [1.0, 0.0, 0.0], 10, $eligible))
        ->toBe([['asset_id' => $asset->id, 'accession_number' => 'PGVECTOR-001', 'title' => null, 'score' => 1.0, 'model_space' => 'test-clip:3:cosine']])
        ->and($store->currentCandidates($generation, $eligible)->toSql())->toContain('"eligible_assets" offset 0')
        ->and($eligible->toSql())->toBe($scopeSql);

    foreach ([
        [$asset, 'lock_version', 2],
        [$asset, 'deleted_at', now()],
        [$embedding, 'source_file_sha256', str_repeat('b', 64)],
        [$file, 'is_primary', false],
        [$file, 'scanner_status', 'infected'],
        [$file, 'ingest_status', 'quarantined'],
        [$embedding, 'stale_at', now()],
        [$generation, 'status', AiEmbeddingGeneration::STATUS_RETIRED],
    ] as [$model, $column, $invalid]) {
        $original = $model->getAttribute($column);
        $model->update([$column => $invalid]);
        expect($store->nearest($generation, [1.0, 0.0, 0.0], 1))->toBeEmpty($column);
        $model->update([$column => $original]);
    }
});

it('ranks the complete generation without truncating denied candidates and preserves eligibility windows', function (): void {
    [$user, $assets] = pgvectorWorkflow(3);
    $run = vectorRun($user, $assets);
    (new ProcessAiIndexJob($run->id))->handle();
    $generation = AiEmbeddingGeneration::query()->sole();
    $store = app(PgvectorEmbeddingStore::class);
    foreach ([$assets[1], $assets[2]] as $asset) {
        $store->persist(AiEmbedding::query()->where('asset_id', $asset->id)->sole(), [0.8, 0.6, 0]);
    }

    $sourceAsset = $assets[0]->fresh()->getAttributes();
    $sourceFile = $assets[0]->files()->sole()->getAttributes();
    $sourceEmbedding = AiEmbedding::query()->where('asset_id', $assets[0]->id)->sole()->getAttributes();
    $assetRows = $fileRows = $embeddingRows = [];
    for ($i = 0; $i < 501; $i++) {
        $assetId = (string) Str::ulid();
        $fileId = (string) Str::ulid();
        $checksum = hash('sha256', 'denied-'.$i);
        $assetRows[] = array_replace($sourceAsset, ['id' => $assetId, 'accession_number' => 'DENIED-'.$i]);
        $fileRows[] = array_replace($sourceFile, [
            'id' => $fileId, 'asset_id' => $assetId, 'storage_key' => 'denied/'.$i.'.jpg', 'sha256' => $checksum,
        ]);
        $embeddingRows[] = array_replace($sourceEmbedding, [
            'id' => (string) Str::ulid(), 'asset_id' => $assetId, 'asset_file_id' => $fileId,
            'source_file_sha256' => $checksum,
        ]);
    }
    DB::table('assets')->insert($assetRows);
    DB::table('asset_files')->insert($fileRows);
    DB::table('ai_embeddings')->insert($embeddingRows);
    $allowed = [$assets[1]->id, $assets[2]->id];
    sort($allowed, SORT_STRING);
    $eligible = Asset::query()->select('assets.id')->whereIn('assets.id', $allowed)->toBase();

    $matches = $store->nearest($generation, [1, 0, 0], 2, $eligible);
    expect(array_column($matches, 'asset_id'))->toBe($allowed)
        ->and(array_column($matches, 'score'))->toBe([0.8, 0.8])
        ->and($store->nearest($generation, [1, 0, 0], 900))->toHaveCount(500)
        ->and($store->nearest($generation, [1, 0, 0], 0, $eligible))->toHaveCount(1);
    $eligible->orderBy('assets.id')->offset(1)->limit(1);
    $sql = $eligible->toSql();
    $bindings = $eligible->getBindings();
    expect(array_column($store->nearest($generation, [1, 0, 0], 2, $eligible), 'asset_id'))->toBe([$allowed[1]])
        ->and($eligible->toSql())->toBe($sql)
        ->and($eligible->getBindings())->toBe($bindings);
    $eligible->whereRaw('1 = 0');
    expect($store->nearest($generation, [1, 0, 0], 2, $eligible))->toBeEmpty();
});

function pgvectorWorkflow(int $count = 2, bool $fakeProvider = true): array
{
    test()->seed(DatabaseSeeder::class);
    Queue::fake();
    Storage::fake('local');
    $user = User::query()->create(['name' => 'Vector admin', 'email' => 'workflow@example.test', 'password' => 'test-password']);
    $user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
    app(AiConfigurationService::class)->update([
        'global_enabled' => true, 'embeddings_enabled' => true, 'local_provider_enabled' => true,
        'local_endpoint' => 'http://127.0.0.1:8088', 'embeddings_provider' => 'local',
        'max_assets_per_batch' => 25, 'derivative_max_pixels' => 512, 'request_timeout_seconds' => 15,
    ], $user);
    $assets = [];
    for ($i = 0; $i < $count; $i++) {
        $asset = Asset::query()->create([
            'accession_number' => 'VECTOR-'.$i, 'title' => 'Vector photo '.$i,
            'created_by_user_id' => $user->id, 'lock_version' => 1,
        ]);
        $image = imagecreatetruecolor(32, 32);
        imagefill($image, 0, 0, ($i + 1) * 0x224466);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Storage::disk('local')->put("vector-{$i}.jpg", $bytes);
        AssetFile::query()->create([
            'asset_id' => $asset->id, 'storage_disk' => 'local', 'storage_key' => "vector-{$i}.jpg",
            'sha256' => hash('sha256', $bytes), 'byte_size' => strlen($bytes), 'media_type' => 'image/jpeg',
            'scanner_status' => 'clean', 'ingest_status' => 'ready_private', 'is_primary' => true,
            'derivatives' => ['preview1200' => "vector-{$i}.jpg"],
        ]);
        $assets[] = $asset;
    }
    Http::preventStrayRequests();
    if ($fakeProvider) {
        Http::fake(['http://127.0.0.1:8088/v1/*' => Http::response(vectorResponse())]);
    }

    return [$user, $assets];
}

function vectorResponse(string $space = 'vector-workflow', array $values = [1.0, 0.0, 0.0]): array
{
    return ['embedding' => $values, 'dimensions' => count($values), 'model_space' => $space];
}

function vectorRun(User $user, array $assets): OperationRun
{
    return app(AiDispatchService::class)->dispatchEmbeddingIndex(array_map(fn (Asset $asset): string => $asset->id, $assets), 'local', $user);
}

it('executes queued indexing and enforces admin and public scopes before the vector limit', function (): void {
    [$user, $assets] = pgvectorWorkflow();
    $run = vectorRun($user, $assets);
    Queue::assertPushed(ProcessAiIndexJob::class);
    (new ProcessAiIndexJob($run->id))->handle();
    expect($run->fresh()->status)->toBe(OperationRun::STATUS_COMPLETED)
        ->and($run->fresh()->processed_items)->toBe(2)
        ->and(AiRun::query()->count())->toBe(2)
        ->and(AiEmbedding::query()->whereNotNull('embedding')->count())->toBe(0);
    $viewer = User::query()->create(['name' => 'Own photos', 'email' => 'viewer@example.test', 'password' => 'test-password']);
    $viewer->roles()->attach(Role::query()->where('key', 'viewer')->firstOrFail());
    $assets[1]->update(['created_by_user_id' => $viewer->id]);
    $search = app(AiSemanticSearchService::class);
    expect(array_column($search->searchAdmin('plein', 'local', $viewer, 1), 'asset_id'))->toBe([$assets[1]->id]);
    $this->actingAs($viewer)->get('/admin/operations/ai/search?q=plein&provider=local')
        ->assertOk()->assertSee('Vector photo 1')->assertDontSee('Vector photo 0');
    $assets[1]->rights()->create(['verification_status' => 'verified', 'rights_holder' => 'Archive']);
    $publication = Publication::query()->create([
        'asset_id' => $assets[1]->id, 'status' => 'published', 'privacy_cleared' => true,
        'published_lock_version' => 1, 'permalink_slug' => 'vector-public', 'published_at' => now(),
    ]);
    expect($search->searchPublic('plein', 'local', 1)->pluck('asset_id')->all())->toBe([$assets[1]->id]);
    $publication->update(['status' => 'revoked']);
    expect($search->searchPublic('plein', 'local', 1))->toBeEmpty();
});

it('rebuilds the same space into a new generation while preserving untouched current photos', function (): void {
    [$user, $assets] = pgvectorWorkflow();
    $first = vectorRun($user, $assets);
    (new ProcessAiIndexJob($first->id))->handle();
    $old = AiEmbeddingGeneration::query()->sole();
    $second = vectorRun($user, [$assets[0]]);
    (new ProcessAiIndexJob($second->id))->handle();
    $new = AiEmbeddingGeneration::query()->where('status', 'active')->sole();
    expect($new->id)->not->toBe($old->id)
        ->and($new->model_space)->toBe($old->model_space)
        ->and($old->fresh()->status)->toBe('retired')
        ->and($new->embeddings()->count())->toBe(2)
        ->and(app(PgvectorEmbeddingStore::class)->nearest($new, [1, 0, 0], 10))->toHaveCount(2);
    Http::assertSentCount(3);
});

it('reports real current coverage repairs only a stale collection item and filters before ranking', function (): void {
    [$user, $assets] = pgvectorWorkflow();
    $run = vectorRun($user, $assets);
    (new ProcessAiIndexJob($run->id))->handle();
    $collection = Collection::query()->create(['title' => 'Target collection', 'slug' => 'target']);
    $collection->assets()->attach($assets[1]);
    $workbench = app(AiIndexWorkbench::class);
    expect($workbench->coverage($user, null)->get()->pluck('coverage_status')->all())->toBe(['current', 'current']);
    expect(array_column(app(AiSemanticSearchService::class)->searchAdmin('photo', 'local', $user, 1, $collection->id), 'asset_id'))->toBe([$assets[1]->id]);
    $this->actingAs($user)->get('/admin/operations/ai/index-workbench?collection='.$collection->id)->assertOk()->assertSee('Actueel: 1');
    $assets[1]->increment('lock_version');
    expect($workbench->coverage($user, $collection->id)->first()->coverage_status)->toBe('stale');
    $head = app(AiIndexGenerationService::class)->activeForProvider('local');
    $child = $workbench->repair($user, $collection->id, [$assets[1]->id], $head->id, $workbench->configurationFingerprint());
    expect($child->payload['asset_ids'])->toBe([$assets[1]->id]);
    (new ProcessAiIndexJob($child->id))->handle();
    expect($workbench->coverage($user, null)->get()->pluck('coverage_status')->all())->toBe(['current', 'current']);
    Http::assertSentCount(4);
});

it('pauses real index generation between items and resumes before activation', function (): void {
    [$user, $assets] = pgvectorWorkflow(fakeProvider: false);
    $run = vectorRun($user, $assets);
    Http::fake(['http://127.0.0.1:8088/v1/embed-image' => function () use ($user, $run) {
        app(OperationWorkbenchService::class)->control($run, $user, 'pause');

        return Http::response(vectorResponse());
    }]);
    (new ProcessAiIndexJob($run->id))->handle();
    expect($run->fresh()->status)->toBe('paused')->and($run->fresh()->payload['cursor'])->toBe(1)
        ->and(AiEmbeddingGeneration::query()->sole()->status)->toBe('building');
    Http::assertSentCount(1);
    Http::fake(['http://127.0.0.1:8088/v1/embed-image' => Http::response(vectorResponse())]);
    app(OperationWorkbenchService::class)->control($run, $user, 'resume');
    (new ProcessAiIndexJob($run->id))->handle();
    expect($run->fresh()->status)->toBe('completed')->and($run->fresh()->processed_items)->toBe(2)
        ->and(AiEmbeddingGeneration::query()->sole()->status)->toBe('active');
});

it('resumes a failed second item without repeating the committed first provider call or its audit', function (): void {
    [$user, $assets] = pgvectorWorkflow(fakeProvider: false);
    Http::fake(['http://127.0.0.1:8088/v1/embed-image' => Http::sequence()
        ->push(vectorResponse())->push(['error' => 'fixture unavailable'], 503)->push(vectorResponse())]);
    $run = vectorRun($user, $assets);
    expect(fn () => (new ProcessAiIndexJob($run->id))->handle())->toThrow(RuntimeException::class);
    expect($run->fresh()->processed_items)->toBe(1)->and($run->fresh()->payload['cursor'])->toBe(1)
        ->and(AiEmbeddingGeneration::query()->sole()->status)->toBe('building');
    (new ProcessAiIndexJob($run->id))->handle();
    expect($run->fresh()->processed_items)->toBe(2)->and($run->fresh()->status)->toBe('completed')
        ->and($run->auditEvents()->where('event_type', 'ai.index.item_succeeded')->count())->toBe(2);
    Http::assertSentCount(3);
    (new ProcessAiIndexJob($run->id))->handle();
    Http::assertSentCount(3);
});

it('recovers stale worker claims after the last checkpoint but before activation without losing counts', function (): void {
    [$user, $assets] = pgvectorWorkflow();
    $this->partialMock(AiIndexGenerationService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('activate')->once()->andThrow(new RuntimeException('fixture: worker interrupted before activation'));
    });
    // Partial mocks do not run the constructor; use a concrete instance for the
    // data-access methods so only the interruption boundary is replaced.
    $real = new AiIndexGenerationService(app(PgvectorEmbeddingStore::class));
    $mock = app(AiIndexGenerationService::class);
    $mock->shouldReceive('forRun')->andReturnUsing(fn ($run) => $real->forRun($run));
    $mock->shouldReceive('create')->andReturnUsing(fn (...$args) => $real->create(...$args));
    $run = vectorRun($user, $assets);
    expect(fn () => (new ProcessAiIndexJob($run->id))->handle())->toThrow(RuntimeException::class, 'interrupted');
    expect($run->fresh()->payload['cursor'])->toBe(2)->and($run->fresh()->processed_items)->toBe(2);
    $this->app->instance(AiIndexGenerationService::class, $real);
    $run->refresh()->update(['status' => 'running', 'started_at' => now()->subSeconds(151), 'claim_token' => 'interrupted-test-claim']);
    (new ProcessAiIndexJob($run->id))->handle();
    expect($run->fresh()->status)->toBe('completed')->and($run->fresh()->processed_items)->toBe(2)
        ->and(AiEmbeddingGeneration::query()->sole()->status)->toBe('active');
    Http::assertSentCount(2);
});

it('rolls back vector receipt and checkpoint together if checkpoint persistence is interrupted', function (): void {
    [$user, $assets] = pgvectorWorkflow(1);
    $run = vectorRun($user, $assets);
    $interrupt = true;
    OperationRun::saving(function (OperationRun $saving) use (&$interrupt): void {
        if ($interrupt && ($saving->payload['cursor'] ?? 0) === 1) {
            $interrupt = false;
            throw new RuntimeException('fixture: checkpoint interrupted');
        }
    });
    expect(fn () => (new ProcessAiIndexJob($run->id))->handle())->toThrow(RuntimeException::class, 'checkpoint interrupted');
    expect(AiEmbedding::query()->count())->toBe(0)->and(AiRun::query()->count())->toBe(0)
        ->and($run->fresh()->payload['cursor'])->toBe(0)->and($run->fresh()->processed_items)->toBe(0);
    (new ProcessAiIndexJob($run->id))->handle();
    expect($run->fresh()->status)->toBe('completed')->and($run->fresh()->processed_items)->toBe(1)
        ->and(AiEmbedding::query()->count())->toBe(1)->and(AiRun::query()->count())->toBe(1);
    // A request completed remotely before its receipt committed is ambiguous;
    // recovery can repeat it, but must never claim exactly-once remote billing.
    Http::assertSentCount(2);
});

it('rejects a model-space change within one run and reuses receipts on manual retry', function (): void {
    [$user, $assets] = pgvectorWorkflow(fakeProvider: false);
    Http::fake(['http://127.0.0.1:8088/v1/embed-image' => Http::sequence()
        ->push(vectorResponse())->push(vectorResponse('other-space'))->push(vectorResponse())]);
    $run = vectorRun($user, $assets);
    $job = new ProcessAiIndexJob($run->id);
    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'modelruimte');
    $job->failed(new RuntimeException('fixture: retries exhausted'));
    expect(AiEmbeddingGeneration::query()->sole()->status)->toBe('building');
    app(OperationRunService::class)->retryRun($run->fresh(), ProcessAiIndexJob::class, $user);
    (new ProcessAiIndexJob($run->id))->handle();
    expect($run->fresh()->status)->toBe('completed')->and($run->fresh()->processed_items)->toBe(2)
        ->and(AiRun::query()->count())->toBe(2);
    Http::assertSentCount(3);
});

it('provisions vector columns idempotently after late extension installation', function (): void {
    DB::statement('ALTER TABLE ai_embeddings DROP COLUMN embedding_vector');
    DB::statement('DROP EXTENSION vector');
    $store = app(PgvectorEmbeddingStore::class);
    expect(fn () => $store->provision())->toThrow(ValidationException::class);
    DB::statement('CREATE EXTENSION vector');
    expect($store->available())->toBeFalse();
    $this->artisan('ai:provision-pgvector')->assertSuccessful();
    $this->artisan('ai:provision-pgvector')->assertSuccessful();
    expect($store->available())->toBeTrue();
});

it('resumes after an actual queue worker process exits abruptly following a committed checkpoint', function (): void {
    [$user, $assets] = pgvectorWorkflow();
    $run = vectorRun($user, $assets);
    $process = new Process([
        PHP_BINARY, base_path('tests/Support/pgvector-interrupted-worker.php'), $run->id, Storage::disk('local')->path(''),
    ], base_path(), timeout: 45);
    $process->run();
    expect($process->getExitCode())->toBe(73, $process->getOutput().$process->getErrorOutput())
        ->and($run->fresh()->status)->toBe('running')
        ->and($run->fresh()->payload['cursor'])->toBe(1)
        ->and($run->fresh()->processed_items)->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
    $run->refresh()->update(['started_at' => now()->subSeconds(151)]);
    (new ProcessAiIndexJob($run->id))->handle();
    expect($run->fresh()->status)->toBe('completed')->and($run->fresh()->processed_items)->toBe(2)
        ->and(AiRun::query()->count())->toBe(2)
        ->and($run->auditEvents()->where('event_type', 'ai.index.item_succeeded')->count())->toBe(2);
    Http::assertSentCount(1);
});

it('does not let an older concurrent rebuild overwrite a newer activated generation', function (): void {
    [$user, $assets] = pgvectorWorkflow();
    $real = new AiIndexGenerationService(app(PgvectorEmbeddingStore::class));
    $mock = Mockery::mock($real)->makePartial();
    $mock->shouldReceive('activate')->once()->andThrow(new RuntimeException('fixture: pause old activation'));
    $this->app->instance(AiIndexGenerationService::class, $mock);
    $older = vectorRun($user, [$assets[0]]);
    expect(fn () => (new ProcessAiIndexJob($older->id))->handle())->toThrow(RuntimeException::class, 'pause old');
    $this->app->instance(AiIndexGenerationService::class, $real);
    $newer = vectorRun($user, [$assets[1]]);
    (new ProcessAiIndexJob($newer->id))->handle();
    expect(fn () => (new ProcessAiIndexJob($older->id))->handle())->toThrow(RuntimeException::class, 'nieuwere indexgeneratie');
    expect(AiEmbeddingGeneration::query()->where('status', 'active')->sole()->operation_run_id)->toBe($newer->id)
        ->and($older->fresh()->status)->not->toBe('completed')
        ->and($newer->auditEvents()->where('event_type', 'ai.index.generation_activated')->count())->toBe(1)
        ->and($older->auditEvents()->where('event_type', 'ai.index.generation_failed')->latest()->first()->context['generation_id'])
        ->toBe(app(AiIndexGenerationService::class)->forRun($older)->id);
});

it('keeps changed sources out of activation and retries the changed item only', function (): void {
    [$user, $assets] = pgvectorWorkflow(fakeProvider: false);
    $calls = 0;
    Http::fake(['http://127.0.0.1:8088/v1/embed-image' => function () use (&$calls, $assets) {
        $calls++;
        if ($calls === 2) {
            $assets[0]->increment('lock_version');
        }

        return Http::response(vectorResponse());
    }]);
    $run = vectorRun($user, $assets);
    expect(fn () => (new ProcessAiIndexJob($run->id))->handle())->toThrow(RuntimeException::class, 'Niet alle geselecteerde bronnen');
    (new ProcessAiIndexJob($run->id))->failed(new RuntimeException('fixture exhausted'));
    app(OperationRunService::class)->retryRun($run->fresh(), ProcessAiIndexJob::class, $user);
    (new ProcessAiIndexJob($run->id))->handle();
    expect($run->fresh()->status)->toBe('completed')->and($run->fresh()->processed_items)->toBe(2);
    Http::assertSentCount(3);
});

it('switches model spaces without copying incompatible old vectors', function (): void {
    [$user, $assets] = pgvectorWorkflow(fakeProvider: false);
    Http::fake(['http://127.0.0.1:8088/v1/embed-image' => Http::sequence()
        ->push(vectorResponse())->push(vectorResponse())->push(vectorResponse('new-model', [0, 1]))]);
    $old = vectorRun($user, $assets);
    (new ProcessAiIndexJob($old->id))->handle();
    $new = vectorRun($user, [$assets[0]]);
    (new ProcessAiIndexJob($new->id))->handle();
    $generation = AiEmbeddingGeneration::query()->where('status', 'active')->sole();
    expect($generation->model_space)->toBe('new-model')->and($generation->dimensions)->toBe(2)
        ->and($generation->embeddings()->count())->toBe(1)
        ->and(app(PgvectorEmbeddingStore::class)->nearest($generation, [0, 1], 10))->toHaveCount(1);
});

it('does not activate when the emergency stop changes during the final provider request', function (): void {
    [$user, $assets] = pgvectorWorkflow(1, fakeProvider: false);
    Http::fake(['http://127.0.0.1:8088/v1/embed-image' => function () use ($user) {
        app(AiConfigurationService::class)->update(['global_enabled' => '0'], $user);

        return Http::response(vectorResponse());
    }]);
    $run = vectorRun($user, $assets);
    (new ProcessAiIndexJob($run->id))->handle();
    expect($run->fresh()->status)->toBe('cancelled')
        ->and($run->fresh()->processed_items)->toBe(1)
        ->and(app(AiIndexGenerationService::class)->activeForProvider('local'))->toBeNull();
});

it('rejects invalid query vectors at the real database boundary', function (array $values): void {
    [$user, $assets] = pgvectorWorkflow(1);
    $run = vectorRun($user, $assets);
    (new ProcessAiIndexJob($run->id))->handle();
    expect(fn () => app(PgvectorEmbeddingStore::class)->nearest(AiEmbeddingGeneration::query()->sole(), $values, 10))
        ->toThrow(ValidationException::class);
})->with(['zero' => [[0, 0, 0]], 'dimension' => [[1, 0]], 'infinite' => [[INF, 0, 0]], 'nan' => [[NAN, 0, 0]]]);
