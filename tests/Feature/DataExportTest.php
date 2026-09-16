<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\DataExchange\Jobs\BuildDataExport;
use App\Modules\DataExchange\Models\DataExport;
use App\Modules\DataExchange\Models\MetadataImport;
use App\Modules\DataExchange\Support\CsvWriter;
use App\Modules\Ingest\Jobs\ProcessUpload;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Ingest\Services\ImageProcessor;
use App\Modules\Ingest\Services\QuarantineUploadService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function exportUser(string $role = 'archivist'): User
{
    $user = User::query()->create(['name' => $role, 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

    return $user;
}

function exportAsset(User $user, array $attributes = []): Asset
{
    return Asset::query()->create(array_replace([
        'accession_number' => 'FA-'.str()->ulid(),
        'created_by_user_id' => $user->id,
        'title' => 'Dorpsstraat',
    ], $attributes));
}

function exportPhoto(User $user): Asset
{
    $asset = exportAsset($user);
    $upload = app(QuarantineUploadService::class)->quarantine($asset, UploadedFile::fake()->image('dorpsstraat.png', 800, 400), $user->id);
    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));

    return $asset->refresh();
}

function exchangeJobIsDue(): bool
{
    return DB::table('jobs')->where('queue', 'ingest')->where('available_at', '<=', now()->getTimestamp())->exists();
}

function exportRun(): void
{
    // Only jobs that are actually due count: a job released back with a delay
    // must not be waited on, or the loop blocks until the worker times out.
    for ($pass = 0; $pass < 10 && exchangeJobIsDue(); $pass++) {
        Artisan::call('queue:work', ['connection' => 'ingest', '--once' => true, '--tries' => 3]);
    }
}

function exportLimitedUser(): User
{
    $role = Role::query()->create(['key' => 'export-'.str()->random(6), 'name' => 'Exporterende vrijwilliger']);
    $role->permissions()->attach(Permission::query()->whereIn('key', ['assets.view', 'assets.create', 'exports.create'])->pluck('id'));
    $user = User::query()->create(['name' => 'Vrijwilliger', 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach($role);

    return $user;
}

function exportRequest(User $user, string $type, array $assetIds, string $scope = 'selection'): DataExport
{
    test()->actingAs($user)->from('/exchange')->post('/exchange/exports', ['export_type' => $type, 'scope' => $scope, 'asset_ids' => $assetIds]);

    return DataExport::query()->latest('id')->firstOrFail();
}

function exportDownload(User $user, DataExport $export): TestResponse
{
    $link = test()->actingAs($user)->post('/exchange/exports/'.$export->id.'/link');
    $location = $link->headers->get('Location');

    return test()->actingAs($user)->get($location === null ? '/exchange' : $location);
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    config(['filesystems.default' => 'local', 'ingest.scanner' => 'none']);
    Storage::fake('local');
});

it('queues a metadata export and serves it only through a short-lived personal link', function (): void {
    $user = exportUser();
    $asset = exportAsset($user, ['title' => 'Kerkplein', 'description' => 'Zicht op het plein']);

    $export = exportRequest($user, 'metadata_json', [$asset->id]);

    expect($export->status)->toBe('queued')
        ->and(DB::table('jobs')->where('queue', 'ingest')->count())->toBe(1)
        ->and($export->storage_key)->toBeNull();

    exportRun();
    $export->refresh();

    expect($export->status)->toBe('ready')
        ->and($export->sha256)->toHaveLength(64)
        ->and($export->expires_at?->isFuture())->toBeTrue()
        ->and($export->manifest['asset_count'])->toBe(1);
    expect(AssetAuditEvent::query()->where('event_type', 'export.included')->count())->toBe(1);

    $this->actingAs($user)->get('/exchange/exports/'.$export->id)->assertOk()->assertSee('Klaar om te downloaden')
        ->assertDontSee($export->storage_key);

    $response = exportDownload($user, $export);
    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private');
    $payload = json_decode($response->streamedContent(), true);
    expect($payload['assets'][0]['title'])->toBe('Kerkplein')
        ->and(json_encode($payload))->not->toContain('exchange/exports')
        ->and($export->fresh()->download_count)->toBe(1);
    expect(AssetAuditEvent::query()->where('event_type', 'export.downloaded')->count())->toBe(1);
});

it('packages private originals, derivatives, a manifest and checksums in a ZIP', function (): void {
    $user = exportUser();
    $asset = exportPhoto($user);

    $export = exportRequest($user, 'package_zip', [$asset->id]);
    exportRun();
    $export->refresh();

    expect($export->status)->toBe('ready')->and($export->byte_size)->toBeGreaterThan(0);

    $response = exportDownload($user, $export);
    $response->assertOk()->assertHeader('Content-Type', 'application/zip');
    $path = tempnam(sys_get_temp_dir(), 'fa-test');
    file_put_contents($path, $response->streamedContent());
    expect(hash_file('sha256', $path))->toBe($export->sha256);

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $names = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $names[] = (string) $zip->getNameIndex($index);
    }
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    $checksums = (string) $zip->getFromName('checksums.sha256');
    $original = collect($names)->first(fn (string $name): bool => str_starts_with($name, 'originals/'));
    expect($names)->toContain('metadata.json')->toContain('metadata.csv')->toContain('checksums.sha256')
        ->and($original)->not->toBeNull()
        ->and(collect($names)->contains(fn (string $name): bool => str_starts_with($name, 'derivatives/')))->toBeTrue()
        ->and($manifest['asset_count'])->toBe(1);
    $file = $asset->files()->sole();
    expect(hash('sha256', (string) $zip->getFromName((string) $original)))->toBe($file->sha256)
        ->and($checksums)->toContain($file->sha256)
        ->and(json_encode($manifest))->not->toContain($file->storage_key);
    $zip->close();
    unlink($path);
});

it('exports CSV that neutralises spreadsheet formulas and can be imported again', function (): void {
    $user = exportUser();
    $asset = exportAsset($user, ['title' => '=SOM(A1:A2)', 'description' => '+kwaadaardig']);

    $export = exportRequest($user, 'metadata_csv', [$asset->id]);
    exportRun();

    $csv = exportDownload($user, $export->fresh())->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
    expect($csv)->toContain("'=SOM(A1:A2)")->toContain("'+kwaadaardig")
        ->and($csv)->toContain('accession_number');

    $import = tap(test()->actingAs($user)->from('/exchange')->post('/exchange/imports', [
        'file' => UploadedFile::fake()->createWithContent('round-trip.csv', $csv),
        'write_mode' => 'overwrite',
    ]), fn () => null);
    $import->assertRedirect();
    expect(MetadataImport::query()->latest('id')->sole()->summary['unchanged'])->toBe(1)
        ->and($asset->fresh()->title)->toBe('=SOM(A1:A2)');
});

it('escapes every formula prefix', function (string $value, string $expected): void {
    expect(app(CsvWriter::class)->guard($value))->toBe($expected);
})->with([
    ['=1+1', "'=1+1"],
    ['+1', "'+1"],
    ['-1', "'-1"],
    ['@SUM', "'@SUM"],
    ["'=already", "''=already"],
    ['gewoon', 'gewoon'],
]);

it('refuses to export photos outside the requester access', function (): void {
    $limited = exportLimitedUser();
    $owner = exportUser('archivist');
    $foreign = exportAsset($owner);

    $this->actingAs($limited)->from('/exchange')
        ->post('/exchange/exports', ['export_type' => 'metadata_json', 'scope' => 'selection', 'asset_ids' => [$foreign->id]])
        ->assertSessionHasErrors('asset_ids');
    expect(DataExport::query()->count())->toBe(0);

    // A limited account exporting everything only ever receives its own photos.
    $mine = exportAsset($limited);
    $export = exportRequest($limited, 'metadata_json', [], 'all');
    expect($export->assetIds())->toBe([$mine->id]);

    foreach (['volunteer', 'viewer'] as $role) {
        $this->actingAs(exportUser($role))
            ->post('/exchange/exports', ['export_type' => 'metadata_json', 'scope' => 'all', 'asset_ids' => []])
            ->assertForbidden();
    }
    expect(DataExport::query()->count())->toBe(1);
});

it('re-checks access when building and when downloading so later changes cannot leak media', function (): void {
    $user = exportLimitedUser();
    $mine = exportAsset($user, ['title' => 'Van mij']);
    $export = exportRequest($user, 'metadata_json', [$mine->id]);
    exportRun();
    expect($export->fresh()->status)->toBe('ready');

    // The photo is handed to someone else after the artifact was built.
    Asset::query()->whereKey($mine->id)->update(['created_by_user_id' => exportUser('archivist')->id]);

    $this->actingAs($user)->post('/exchange/exports/'.$export->id.'/link')->assertForbidden();
    $export->refresh();
    expect($export->status)->toBe('revoked')->and($export->storage_key)->toBeNull();
    expect(Storage::disk('local')->allFiles('exchange/exports'))->toBe([]);
});

it('rejects a stale, foreign or tampered download link', function (): void {
    $user = exportUser();
    $asset = exportAsset($user);
    $export = exportRequest($user, 'metadata_csv', [$asset->id]);
    exportRun();
    $export->refresh();

    $link = $this->actingAs($user)->post('/exchange/exports/'.$export->id.'/link');
    $url = (string) $link->headers->get('Location');

    $intruder = exportUser('archivist');
    $this->actingAs($intruder)->get($url)->assertNotFound();
    $this->actingAs($intruder)->get('/exchange/exports/'.$export->id)->assertNotFound();

    $this->actingAs($user)->get('/exchange/exports/'.$export->id.'/download/'.str_repeat('a', 64))->assertForbidden();

    DataExport::query()->whereKey($export->id)->update(['download_token_expires_at' => now()->subMinute()]);
    $this->actingAs($user)->get($url)->assertForbidden();

    DataExport::query()->whereKey($export->id)->update(['expires_at' => now()->subMinute()]);
    $this->actingAs($user)->post('/exchange/exports/'.$export->id.'/link')->assertStatus(410);
});

it('prunes expired artifacts and reports the cleanup', function (): void {
    $user = exportUser();
    $asset = exportAsset($user);
    $export = exportRequest($user, 'metadata_json', [$asset->id]);
    exportRun();
    $key = (string) $export->fresh()->storage_key;
    expect(Storage::disk('local')->exists($key))->toBeTrue();

    DataExport::query()->whereKey($export->id)->update(['expires_at' => now()->subMinute()]);
    Artisan::call('exchange:prune-exports');

    expect(Storage::disk('local')->exists($key))->toBeFalse()
        ->and($export->fresh()->status)->toBe('expired')
        ->and($export->fresh()->storage_key)->toBeNull();
    $this->actingAs($user)->get('/exchange/exports/'.$export->id)->assertOk()->assertSee('Verlopen');
    $this->actingAs($user)->post('/exchange/exports/'.$export->id.'/link')->assertStatus(410);
});

it('enforces the selection bound', function (): void {
    $user = exportUser();
    $first = exportAsset($user);
    $second = exportAsset($user);
    config(['exchange.max_export_assets' => 1]);

    $this->actingAs($user)->from('/exchange')
        ->post('/exchange/exports', ['export_type' => 'metadata_json', 'scope' => 'all', 'asset_ids' => []])
        ->assertSessionHasErrors('asset_ids');
    $this->actingAs($user)->from('/exchange')
        ->post('/exchange/exports', ['export_type' => 'metadata_json', 'scope' => 'selection', 'asset_ids' => [$first->id, $second->id]])
        ->assertSessionHasErrors('asset_ids');
    expect(DataExport::query()->count())->toBe(0);
});

it('fails safely when the stored original disappeared and can be retried', function (): void {
    $user = exportUser();
    $asset = exportPhoto($user);
    Storage::disk('local')->delete((string) $asset->files()->sole()->storage_key);

    $export = exportRequest($user, 'package_zip', [$asset->id]);
    exportRun();
    $export->refresh();

    expect($export->status)->toBe('failed')->and($export->failure_reason)->toContain('Samenstellen mislukt')
        ->and($export->storage_key)->toBeNull();
    $this->actingAs($user)->get('/exchange/exports/'.$export->id)->assertOk()->assertSee('Export mislukt');
    $this->actingAs($user)->post('/exchange/exports/'.$export->id.'/link')->assertStatus(410);

    $this->actingAs($user)->post('/exchange/exports/'.$export->id.'/retry')->assertRedirect();
    expect($export->fresh()->status)->toBe('queued');

    (new BuildDataExport($export->id))->failed(null);
    expect($export->fresh()->status)->toBe('failed');
});

it('keeps export screens behind authentication and export rights', function (): void {
    $this->get('/exchange/exports/'.str()->ulid())->assertRedirect('/login');
    $this->post('/exchange/exports', ['export_type' => 'metadata_json', 'scope' => 'all'])->assertRedirect('/login');
    expect(DataExport::query()->count())->toBe(0);
});

it('never exposes the private storage key of an export artifact', function (): void {
    $user = exportUser();
    $asset = exportPhoto($user);
    $export = exportRequest($user, 'package_zip', [$asset->id]);
    exportRun();
    $key = (string) $export->fresh()->storage_key;

    $this->actingAs($user)->get('/exchange/exports/'.$export->id)->assertOk()->assertDontSee($key);
    $this->actingAs($user)->get('/'.$key)->assertNotFound();
    $this->actingAs($user)->get('/storage/'.$key)->assertNotFound();
});

it('excludes photos that became inaccessible while the export was queued', function (): void {
    $user = exportUser('archivist');
    $keep = exportAsset($user, ['title' => 'Blijft']);
    $lost = exportAsset($user, ['title' => 'Verdwijnt']);
    $export = exportRequest($user, 'metadata_json', [$keep->id, $lost->id]);

    Asset::query()->whereKey($lost->id)->delete();
    exportRun();
    $export->refresh();

    expect($export->status)->toBe('ready')->and($export->asset_count)->toBe(1)
        ->and($export->manifest['skipped'])->toHaveCount(1);
    $payload = json_decode(exportDownload($user, $export)->streamedContent(), true);
    expect($payload['assets'])->toHaveCount(1)->and($payload['assets'][0]['title'])->toBe('Blijft');
});

it('offers the export form in Dutch and lists the requested exports', function (): void {
    $user = exportUser();
    $asset = exportAsset($user, ['title' => 'Molen aan de vaart']);

    $this->actingAs($user)->get('/exchange')->assertOk()
        ->assertSee('Exporteren')
        ->assertSee('Volledig pakket (ZIP met originelen en afgeleiden)')
        ->assertSee('Molen aan de vaart');

    $export = exportRequest($user, 'metadata_csv', [$asset->id]);
    $this->actingAs($user)->get('/exchange')->assertOk()
        ->assertSee('Metadata (CSV)')
        ->assertSee('In wachtrij');

    // A user without export rights never sees the form.
    $this->actingAs(exportUser('volunteer'))->get('/exchange')->assertOk()->assertDontSee('Volledig pakket (ZIP met originelen en afgeleiden)');
    expect($export->status)->toBe('queued');
});

it('lets a redelivered job take over an export abandoned by a killed worker', function (): void {
    $user = exportUser();
    $asset = exportAsset($user, ['title' => 'Na de crash']);
    $export = exportRequest($user, 'metadata_json', [$asset->id]);

    // Exactly what a killed or timed-out worker leaves behind: claimed, running,
    // never released. The queue redelivers the job after retry_after.
    DataExport::query()->whereKey($export->id)->update([
        'status' => 'running',
        'claim_token' => (string) str()->uuid(),
        'started_at' => now()->subSeconds((int) config('exchange.stale_claim_seconds') + 60),
        'attempts' => 1,
    ]);
    exportRun();
    $export->refresh();

    expect($export->status)->toBe('ready')
        ->and($export->attempts)->toBe(2)
        ->and($export->storage_key)->not->toBeNull();
    exportDownload($user, $export)->assertOk();
});

it('never steals an export from a worker that is still building it', function (): void {
    $user = exportUser();
    $asset = exportAsset($user);
    $export = exportRequest($user, 'metadata_json', [$asset->id]);
    $token = (string) str()->uuid();
    DataExport::query()->whereKey($export->id)->update([
        'status' => 'running',
        'claim_token' => $token,
        'started_at' => now()->subSeconds((int) config('exchange.stale_claim_seconds') - 60),
    ]);

    exportRun();

    $export->refresh();
    expect($export->status)->toBe('running')->and($export->claim_token)->toBe($token);
    // And the job that could not claim is still on the queue, delayed rather
    // than deleted. Reporting success here was the defect that left a killed
    // build "bezig" for ever: the queue redelivers only until a job reports
    // done, so the one job able to finish the export disappeared.
    $job = DB::table('jobs')->where('queue', 'ingest')->first();
    expect($job)->not->toBeNull()
        ->and((int) $job->available_at)->toBeGreaterThan(now()->getTimestamp());
});

it('keeps the job timeout under the queue retry window so a redelivery can reclaim', function (): void {
    $timeout = (int) config('exchange.job_timeout_seconds');
    $stale = (int) config('exchange.stale_claim_seconds');
    $retryAfter = (int) config('queue.connections.ingest.retry_after');

    // The ordering is the whole recovery contract. Above retry_after the single
    // redelivery arrives too early to reclaim; below the job timeout a live
    // worker gets robbed. Both were observed against a real worker.
    expect($timeout)->toBeLessThan($stale)
        ->and($stale)->toBeLessThan($retryAfter);

    $export = exportRequest(exportUser(), 'metadata_json', [exportAsset(exportUser())->id]);
    $payload = json_decode((string) DB::table('jobs')->where('queue', 'ingest')->value('payload'), true);
    expect($payload['timeout'])->toBe($timeout)->and($export->status)->toBe('queued');
});

it('releases an export whose worker never returned so the owner can retry', function (): void {
    $user = exportUser();
    $asset = exportAsset($user);
    $export = exportRequest($user, 'metadata_json', [$asset->id]);
    // No job left to redeliver: the row would otherwise stay busy for ever.
    DB::table('jobs')->where('queue', 'ingest')->delete();
    DataExport::query()->whereKey($export->id)->update([
        'status' => 'running',
        'claim_token' => (string) str()->uuid(),
        'started_at' => now()->subSeconds((int) config('exchange.abandoned_claim_seconds') + 60),
    ]);

    Artisan::call('exchange:prune-exports');

    $export->refresh();
    expect($export->status)->toBe('failed')
        ->and($export->failure_reason)->toContain('worker is gestopt')
        ->and($export->claim_token)->toBeNull();
    $this->actingAs($user)->get('/exchange/exports/'.$export->id)->assertOk()->assertSee('Export mislukt');

    $this->actingAs($user)->post('/exchange/exports/'.$export->id.'/retry')->assertRedirect();
    exportRun();
    expect($export->fresh()->status)->toBe('ready');
});

it('leaves a recently started export alone when recovering', function (): void {
    $user = exportUser();
    $asset = exportAsset($user);
    $export = exportRequest($user, 'metadata_json', [$asset->id]);
    DataExport::query()->whereKey($export->id)->update([
        'status' => 'running',
        'claim_token' => (string) str()->uuid(),
        'started_at' => now()->subSeconds((int) config('exchange.abandoned_claim_seconds') - 60),
    ]);

    Artisan::call('exchange:prune-exports');

    expect($export->fresh()->status)->toBe('running');
});
