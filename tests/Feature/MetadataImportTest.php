<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\DataExchange\Jobs\RunMetadataImport;
use App\Modules\DataExchange\Models\MetadataImport;
use App\Modules\DataExchange\Services\MetadataImportService;
use App\Modules\Ingest\Models\AssetAuditEvent;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function exchangeUser(string $role = 'archivist'): User
{
    $user = User::query()->create(['name' => $role, 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

    return $user;
}

function exchangeAsset(User $user, array $attributes = []): Asset
{
    return Asset::query()->create(array_replace([
        'accession_number' => 'FA-'.str()->ulid(),
        'created_by_user_id' => $user->id,
        'title' => null,
    ], $attributes));
}

function exchangeCsv(string $content, string $name = 'import.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function exchangeUpload(User $user, string $content, string $mode = 'fill_empty', string $name = 'import.csv'): MetadataImport
{
    test()->actingAs($user)->from('/exchange')->post('/exchange/imports', ['file' => exchangeCsv($content, $name), 'write_mode' => $mode]);

    return MetadataImport::query()->latest('id')->firstOrFail();
}

function exchangeRun(): void
{
    Artisan::call('queue:work', ['connection' => 'ingest', '--once' => true, '--tries' => 3]);
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    config(['filesystems.default' => 'local']);
    Storage::fake('local');
});

it('previews a CSV mapping and changes nothing until the import is confirmed', function (): void {
    $user = exchangeUser();
    $asset = exchangeAsset($user);
    $csv = "archiefnummer;versie;titel;beschrijving;trefwoorden;rechtenstatus;onbekende_kolom\n"
        .$asset->accession_number.";1;Kerkplein;Zicht op het plein;plein, kerk;verified;negeer mij\n";

    $import = exchangeUpload($user, $csv);

    expect($import->status)->toBe('analysed')
        ->and($import->delimiter)->toBe(';')
        ->and($import->summary['ready'])->toBe(1)
        ->and($import->row_count)->toBe(1)
        ->and($asset->fresh()->title)->toBeNull()
        ->and($asset->fresh()->lock_version)->toBe(1);
    expect(collect($import->column_mapping)->firstWhere('column', 'onbekende_kolom')['status'])->toBe('ignored');

    $this->actingAs($user)->get('/exchange/imports/'.$import->id)->assertOk()
        ->assertSee('Kerkplein')->assertSee('Importvoorbeeld')->assertSee('Klaar');

    $this->actingAs($user)->from('/exchange/imports/'.$import->id)
        ->post('/exchange/imports/'.$import->id.'/confirm', ['checksum' => str_repeat('a', 64)])
        ->assertSessionHasErrors('file');
    expect($import->fresh()->status)->toBe('analysed');

    $this->actingAs($user)->post('/exchange/imports/'.$import->id.'/confirm', ['checksum' => $import->content_sha256])
        ->assertRedirect('/exchange/imports/'.$import->id);
    expect($import->fresh()->status)->toBe('queued')
        ->and(DB::table('jobs')->where('queue', 'ingest')->count())->toBe(1)
        ->and($asset->fresh()->title)->toBeNull();

    exchangeRun();

    $asset->refresh();
    expect($import->fresh()->status)->toBe('completed')
        ->and($import->fresh()->summary['applied'])->toBe(1)
        ->and($asset->title)->toBe('Kerkplein')
        ->and($asset->description)->toBe('Zicht op het plein')
        ->and($asset->lock_version)->toBe(2)
        ->and($asset->tags()->pluck('name')->sort()->values()->all())->toBe(['kerk', 'plein'])
        ->and($asset->rights()->sole()->verification_status)->toBe('verified');
    $event = AssetAuditEvent::query()->where('event_type', 'metadata.imported')->sole();
    expect($event->asset_id)->toBe($asset->id)
        ->and($event->actor_user_id)->toBe($user->id)
        ->and($event->details['import_id'])->toBe($import->id)
        ->and($event->details['before']['metadata']['title'])->toBeNull()
        ->and($event->details['after']['metadata']['title'])->toBe('Kerkplein');
});

it('protects filled values unless overwriting is explicitly chosen', function (): void {
    $user = exchangeUser();
    $asset = exchangeAsset($user, ['title' => 'Bestaande titel', 'description' => null]);
    $csv = "accession_number,lock_version,title,description\n".$asset->accession_number.",1,Nieuwe titel,Nieuwe beschrijving\n";

    $import = exchangeUpload($user, $csv);
    $this->actingAs($user)->post('/exchange/imports/'.$import->id.'/confirm', ['checksum' => $import->content_sha256]);
    exchangeRun();

    $asset->refresh();
    expect($asset->title)->toBe('Bestaande titel')->and($asset->description)->toBe('Nieuwe beschrijving');

    $overwrite = exchangeUpload($user, "accession_number,lock_version,title\n".$asset->accession_number.",2,Nieuwe titel\n", 'overwrite');
    $this->actingAs($user)->post('/exchange/imports/'.$overwrite->id.'/confirm', ['checksum' => $overwrite->content_sha256]);
    exchangeRun();

    expect($asset->fresh()->title)->toBe('Nieuwe titel')->and($asset->fresh()->lock_version)->toBe(3);
});

it('never clears existing data with blank cells', function (): void {
    $user = exchangeUser();
    $asset = exchangeAsset($user, ['title' => 'Bewaar mij', 'description' => 'Bewaar ook mij']);
    $import = exchangeUpload($user, "accession_number,lock_version,title,description\n".$asset->accession_number.",1,,\n", 'overwrite');

    expect($import->status)->toBe('analysed')
        ->and($import->summary['unchanged'])->toBe(1)
        ->and($import->summary['ready'])->toBe(0);
    $this->actingAs($user)->from('/exchange/imports/'.$import->id)
        ->post('/exchange/imports/'.$import->id.'/confirm', ['checksum' => $import->content_sha256])
        ->assertSessionHasErrors('file');
    expect($asset->fresh()->title)->toBe('Bewaar mij')->and($asset->fresh()->description)->toBe('Bewaar ook mij');
});

it('refuses stale versions during the preview and during the confirmed run', function (): void {
    $user = exchangeUser();
    $asset = exchangeAsset($user);
    $stale = exchangeUpload($user, "accession_number,lock_version,title\n".$asset->accession_number.",7,Titel\n");
    expect($stale->summary['error'])->toBe(1)
        ->and($stale->rows()->sole()->messages[0])->toContain('komt niet overeen');

    $import = exchangeUpload($user, "accession_number,lock_version,title\n".$asset->accession_number.",1,Titel uit CSV\n");
    expect($import->summary['ready'])->toBe(1);
    $this->actingAs($user)->post('/exchange/imports/'.$import->id.'/confirm', ['checksum' => $import->content_sha256]);
    Asset::query()->whereKey($asset->id)->update(['title' => 'Handmatig gewijzigd', 'lock_version' => 2]);
    exchangeRun();

    expect($import->fresh()->status)->toBe('completed')
        ->and($import->fresh()->summary['failed'])->toBe(1)
        ->and($import->rows()->sole()->status)->toBe('failed')
        ->and($asset->fresh()->title)->toBe('Handmatig gewijzigd')
        ->and($asset->fresh()->lock_version)->toBe(2);
});

it('never creates assets and never touches photos outside the uploader access', function (): void {
    $volunteer = exchangeUser('volunteer');
    $other = exchangeUser('volunteer');
    $foreign = exchangeAsset($other, ['title' => 'Van iemand anders']);
    $csv = "accession_number,lock_version,title\nFA-BESTAATNIET,1,Nieuw\n".$foreign->accession_number.",1,Gekaapt\n";

    $import = exchangeUpload($volunteer, $csv);

    expect($import->summary['error'])->toBe(2)->and(Asset::query()->count())->toBe(1)
        ->and($import->rows()->where('row_number', 1)->sole()->messages[0])->toContain('nooit nieuwe')
        ->and($import->rows()->where('row_number', 2)->sole()->messages[0])->toContain('niet bijwerken')
        ->and($foreign->fresh()->title)->toBe('Van iemand anders');
});

it('rejects duplicate accession numbers inside one file', function (): void {
    $user = exchangeUser();
    $asset = exchangeAsset($user);
    $csv = "accession_number,lock_version,title\n".$asset->accession_number.",1,Eerste\n".$asset->accession_number.",1,Tweede\n";

    $import = exchangeUpload($user, $csv);

    expect($import->summary['ready'])->toBe(1)->and($import->summary['error'])->toBe(1)
        ->and($import->rows()->where('row_number', 2)->sole()->messages[0])->toContain('meerdere keren');
});

it('strips the spreadsheet formula guard so an export round trip is lossless', function (): void {
    $user = exchangeUser();
    $asset = exchangeAsset($user);
    $csv = "accession_number,lock_version,title\n".$asset->accession_number.",1,\"'=SOM(A1:A2)\"\n";

    $import = exchangeUpload($user, $csv);
    $this->actingAs($user)->post('/exchange/imports/'.$import->id.'/confirm', ['checksum' => $import->content_sha256]);
    exchangeRun();

    expect($asset->fresh()->title)->toBe('=SOM(A1:A2)');
});

it('validates dates and rights values without writing anything', function (string $csvRow, string $expected): void {
    $user = exchangeUser();
    $asset = exchangeAsset($user);
    $import = exchangeUpload($user, "accession_number,lock_version,date_precision,date_earliest,date_latest,rights_status\n".$asset->accession_number.','.$csvRow."\n");

    expect($import->summary['error'])->toBe(1)
        ->and(implode(' ', $import->rows()->sole()->messages))->toContain($expected)
        ->and($asset->fresh()->date_precision)->toBe('unknown');
})->with([
    ['1,range,1900-01-01,,unverified', 'bereik vereist'],
    ['1,exact,1900-13-45,,unverified', 'formaat'],
    ['1,unknown,1900-01-01,,unverified', 'leeg blijven'],
    ['1,verzonnen,1900-01-01,,unverified', 'date_precision moet'],
    ['1,exact,1900-01-01,1900-01-01,verzonnen', 'rights_status moet'],
]);

it('enforces upload and file bounds', function (): void {
    $user = exchangeUser();
    $asset = exchangeAsset($user);
    $row = "accession_number,lock_version,title\n".$asset->accession_number.",1,Titel\n";

    config(['exchange.max_import_bytes' => 10]);
    $this->actingAs($user)->from('/exchange')->post('/exchange/imports', ['file' => exchangeCsv($row), 'write_mode' => 'fill_empty'])
        ->assertSessionHasErrors('file');
    config(['exchange.max_import_bytes' => 5_242_880]);

    $this->actingAs($user)->from('/exchange')->post('/exchange/imports', ['file' => UploadedFile::fake()->image('import.png'), 'write_mode' => 'fill_empty'])
        ->assertSessionHasErrors('file');
    expect(MetadataImport::query()->count())->toBe(0);

    config(['exchange.max_import_rows' => 1]);
    $many = exchangeUpload($user, $row.$asset->accession_number.",1,Tweede\n");
    expect($many->status)->toBe('failed')->and($many->failure_reason)->toContain('meer dan 1 rijen');
    config(['exchange.max_import_rows' => 5000]);

    $binary = exchangeUpload($user, "accession_number,lock_version,title\n".$asset->accession_number.",1,\xff\xfe geen utf8\n");
    expect($binary->status)->toBe('failed')->and($binary->failure_reason)->toContain('UTF-8');

    $missing = exchangeUpload($user, "titel,beschrijving\nA,B\n");
    expect($missing->status)->toBe('failed')->and($missing->failure_reason)->toContain('accession_number');
    expect($asset->fresh()->title)->toBeNull();
});

it('analyses heavy files in the background on the ingest queue', function (): void {
    $user = exchangeUser();
    $asset = exchangeAsset($user);
    config(['exchange.sync_analysis_bytes' => 1]);

    $import = exchangeUpload($user, "accession_number,lock_version,title\n".$asset->accession_number.",1,Achtergrond\n");

    expect($import->status)->toBe('analysing')->and(DB::table('jobs')->where('queue', 'ingest')->count())->toBe(1);
    $this->actingAs($user)->get('/exchange/imports/'.$import->id)->assertOk()->assertSee('worker');

    exchangeRun();

    expect($import->fresh()->status)->toBe('analysed')->and($import->fresh()->summary['ready'])->toBe(1)
        ->and($asset->fresh()->title)->toBeNull();
});

it('keeps imports private and requires the update permission', function (): void {
    $user = exchangeUser();
    $asset = exchangeAsset($user);
    $import = exchangeUpload($user, "accession_number,lock_version,title\n".$asset->accession_number.",1,Titel\n");

    $intruder = exchangeUser('archivist');
    $this->actingAs($intruder)->get('/exchange/imports/'.$import->id)->assertNotFound();
    $this->actingAs($intruder)->post('/exchange/imports/'.$import->id.'/confirm', ['checksum' => $import->content_sha256])->assertNotFound();

    $viewer = exchangeUser('viewer');
    $this->actingAs($viewer)->post('/exchange/imports', ['file' => exchangeCsv("a,b\n1,2\n"), 'write_mode' => 'fill_empty'])->assertForbidden();
    $this->actingAs($viewer)->get('/exchange')->assertOk()->assertDontSee('CSV importeren');

    expect($asset->fresh()->title)->toBeNull();
});

it('keeps the exchange screens behind authentication', function (): void {
    $this->get('/exchange')->assertRedirect('/login');
    $this->post('/exchange/imports', ['file' => exchangeCsv("a,b\n1,2\n"), 'write_mode' => 'fill_empty'])->assertRedirect('/login');
    expect(MetadataImport::query()->count())->toBe(0);
});

it('can retry a run that failed and stays idempotent afterwards', function (): void {
    $user = exchangeUser();
    $asset = exchangeAsset($user);
    $import = exchangeUpload($user, "accession_number,lock_version,title\n".$asset->accession_number.",1,Na herstel\n");

    $this->actingAs($user)->post('/exchange/imports/'.$import->id.'/confirm', ['checksum' => $import->content_sha256]);
    (new RunMetadataImport($import->id, 'apply'))->failed(null);
    expect($import->fresh()->status)->toBe('failed')->and($asset->fresh()->title)->toBeNull();

    DB::table('jobs')->delete();
    $this->actingAs($user)->post('/exchange/imports/'.$import->id.'/confirm', ['checksum' => $import->content_sha256]);
    exchangeRun();

    expect($import->fresh()->status)->toBe('completed')->and($asset->fresh()->title)->toBe('Na herstel')
        ->and($asset->fresh()->lock_version)->toBe(2);

    (new RunMetadataImport($import->id, 'apply'))->handle(app(MetadataImportService::class));
    expect($asset->fresh()->lock_version)->toBe(2)
        ->and(AssetAuditEvent::query()->where('event_type', 'metadata.imported')->count())->toBe(1);
});

it('lets a redelivered job finish an import abandoned by a killed worker', function (): void {
    $user = exchangeUser();
    $asset = exchangeAsset($user);
    $import = exchangeUpload($user, "accession_number,lock_version,title\n".$asset->accession_number.",1,Na de crash\n");
    $this->actingAs($user)->post('/exchange/imports/'.$import->id.'/confirm', ['checksum' => $import->content_sha256]);

    // What a killed or timed-out worker leaves behind, mid-apply.
    MetadataImport::query()->whereKey($import->id)->update([
        'status' => 'running',
        'claim_token' => (string) str()->uuid(),
        'started_at' => now()->subSeconds((int) config('exchange.stale_claim_seconds') + 60),
    ]);
    exchangeRun();

    expect($import->fresh()->status)->toBe('completed')
        ->and($asset->fresh()->title)->toBe('Na de crash')
        ->and($asset->fresh()->lock_version)->toBe(2);
});

it('never steals an import from a worker that is still applying it', function (): void {
    $user = exchangeUser();
    $asset = exchangeAsset($user);
    $import = exchangeUpload($user, "accession_number,lock_version,title\n".$asset->accession_number.",1,Niet stelen\n");
    $this->actingAs($user)->post('/exchange/imports/'.$import->id.'/confirm', ['checksum' => $import->content_sha256]);
    $token = (string) str()->uuid();
    MetadataImport::query()->whereKey($import->id)->update([
        'status' => 'running',
        'claim_token' => $token,
        'started_at' => now()->subSeconds((int) config('exchange.stale_claim_seconds') - 60),
    ]);

    exchangeRun();

    expect($import->fresh()->status)->toBe('running')
        ->and($import->fresh()->claim_token)->toBe($token)
        ->and($asset->fresh()->title)->toBeNull();
    // The job that could not claim stays on the queue, delayed rather than
    // deleted: reporting success would throw away the only job able to finish
    // this run, leaving it "bezig" for ever.
    $job = DB::table('jobs')->where('queue', 'ingest')->first();
    expect($job)->not->toBeNull()
        ->and((int) $job->available_at)->toBeGreaterThan(now()->getTimestamp());
});

it('releases an import whose worker never returned so it can be confirmed again', function (): void {
    $user = exchangeUser();
    $asset = exchangeAsset($user);
    $import = exchangeUpload($user, "accession_number,lock_version,title\n".$asset->accession_number.",1,Hersteld\n");
    $this->actingAs($user)->post('/exchange/imports/'.$import->id.'/confirm', ['checksum' => $import->content_sha256]);
    // No job left to redeliver.
    DB::table('jobs')->where('queue', 'ingest')->delete();
    MetadataImport::query()->whereKey($import->id)->update([
        'status' => 'running',
        'claim_token' => (string) str()->uuid(),
        'started_at' => now()->subSeconds((int) config('exchange.abandoned_claim_seconds') + 60),
    ]);

    Artisan::call('exchange:recover-imports');

    expect($import->fresh()->status)->toBe('failed')
        ->and($import->fresh()->failure_reason)->toContain('worker is gestopt')
        ->and($import->fresh()->claim_token)->toBeNull()
        ->and($asset->fresh()->title)->toBeNull();

    $this->actingAs($user)->post('/exchange/imports/'.$import->id.'/confirm', ['checksum' => $import->content_sha256])
        ->assertRedirect('/exchange/imports/'.$import->id);
    exchangeRun();
    expect($import->fresh()->status)->toBe('completed')->and($asset->fresh()->title)->toBe('Hersteld');
});

it('leaves a recently started import alone when recovering', function (): void {
    $user = exchangeUser();
    $asset = exchangeAsset($user);
    $import = exchangeUpload($user, "accession_number,lock_version,title\n".$asset->accession_number.",1,Bezig\n");
    $this->actingAs($user)->post('/exchange/imports/'.$import->id.'/confirm', ['checksum' => $import->content_sha256]);
    MetadataImport::query()->whereKey($import->id)->update([
        'status' => 'running',
        'claim_token' => (string) str()->uuid(),
        'started_at' => now()->subSeconds((int) config('exchange.abandoned_claim_seconds') - 60),
    ]);

    Artisan::call('exchange:recover-imports');

    expect($import->fresh()->status)->toBe('running');
});
