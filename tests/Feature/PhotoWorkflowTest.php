<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Ingest\Jobs\ProcessUpload;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Ingest\Models\QuarantineUpload;
use App\Modules\Ingest\Services\ImageProcessor;
use App\Modules\Ingest\Services\MalwareScanner;
use App\Modules\Ingest\Services\QuarantineUploadService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function photoUser(string $role = 'administrator'): User
{
    $user = User::query()->create(['name' => $role, 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

    return $user;
}

function photoUpload(User $user, string $filename = 'image.png', int $width = 2400, int $height = 1200): QuarantineUpload
{
    $asset = Asset::query()->create(['accession_number' => (string) str()->ulid(), 'created_by_user_id' => $user->id, 'title' => 'Original title']);

    return app(QuarantineUploadService::class)->quarantine($asset, UploadedFile::fake()->image($filename, $width, $height), $user->id);
}

function photoMetadata(array $overrides = []): array
{
    return array_replace(['lock_version' => 1, 'title' => 'Straatbeeld', 'description' => 'Historische foto', 'date_precision' => 'unknown', 'date_earliest' => null, 'date_latest' => null, 'tags' => 'straat, erfgoed', 'rights_status' => 'unverified', 'rights_holder' => 'Onbekend', 'rights_note' => 'Nog onderzoeken'], $overrides);
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    config(['filesystems.default' => 'local', 'ingest.scanner' => 'none']);
    Storage::fake('local');
});

it('runs real queued processing with immutable private originals and bounded stripped derivatives', function (string $disk, string $extension): void {
    Storage::fake($disk);
    config(['filesystems.default' => $disk]);
    $user = photoUser();
    $upload = photoUpload($user, 'image.'.$extension);
    $original = Storage::disk($disk)->get($upload->storage_key);
    expect(AssetFile::count())->toBe(0)->and(DB::table('jobs')->where('queue', 'ingest')->count())->toBe(1);
    Artisan::call('queue:work', ['connection' => 'ingest', '--once' => true, '--tries' => 3]);
    expect($upload->fresh()->status)->toBe('completed');
    $file = AssetFile::query()->sole();
    expect($file->scanner_status)->toBe('unscanned')->and($file->publishable_at)->toBeNull()
        ->and($file->scanned_at)->toBeNull()->and($file->ingest_status)->toBe('ready_private')
        ->and($file->storage_disk)->toBe($disk)->and($file->sha256)->toBe(hash('sha256', $original))
        ->and(Storage::disk($disk)->get($file->storage_key))->toBe($original);
    foreach ([300, 1200, 2000] as $size) {
        $bytes = Storage::disk($disk)->get($file->derivatives['preview'.$size]);
        $info = getimagesizefromstring($bytes);
        expect($info[0])->toBe($size)->and($info[1])->toBe((int) ($size / 2))
            ->and($info['mime'])->toBe('image/jpeg')->and(str_contains($bytes, "Exif\0\0"))->toBeFalse();
    }
    $this->actingAs($user)->get('/admin/assets/'.$file->asset_id)->assertOk()->assertSee('NIET GESCAND');
    $this->get('/admin/assets/'.$file->asset_id.'/files/'.$file->id.'/media/preview300')
        ->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get('/'.$file->storage_key)->assertNotFound();
    $this->get('/storage/'.$file->storage_key)->assertNotFound();
    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));
    expect(AssetFile::count())->toBe(1)->and($upload->fresh()->attempts)->toBe(1);
})->with(['local', 's3'])->with(['png', 'jpg', 'webp']);

it('keeps duplicate rejection permanent without revealing another asset identity', function (): void {
    $user = photoUser();
    $first = photoUpload($user);
    (new ProcessUpload($first->id))->handle(app(ImageProcessor::class));
    $second = photoUpload($user);
    (new ProcessUpload($second->id))->handle(app(ImageProcessor::class));
    expect($second->fresh()->status)->toBe('rejected')
        ->and($second->fresh()->failure_reason)->not->toContain($first->asset_id)
        ->and(AssetFile::count())->toBe(1);
    $this->actingAs($user)->post('/admin/assets/'.$second->asset_id.'/uploads/'.$second->id.'/retry')->assertConflict();
});

it('rejects invalid MIME types without creating assets or queued work', function (string $mime): void {
    $this->actingAs(photoUser())->postJson('/admin/assets', ['files' => [UploadedFile::fake()->create('bad.file', 5, $mime)]])
        ->assertUnprocessable()->assertJsonPath('results.0.ok', false);
    expect(Asset::count())->toBe(0)->and(QuarantineUpload::count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
})->with(['image/tiff', 'image/svg+xml', 'application/pdf', 'text/plain']);

it('enforces byte and pixel limits and avoids processing rejected bytes', function (): void {
    $user = photoUser();
    config(['ingest.max_upload_bytes' => 1]);
    $this->actingAs($user)->postJson('/admin/assets', ['files' => [UploadedFile::fake()->image('large.png')]])->assertUnprocessable();
    expect(Asset::count())->toBe(0);
    config(['ingest.max_upload_bytes' => 104857600, 'ingest.max_image_pixels' => 10]);
    $upload = photoUpload($user, 'pixels.png', 100, 100);
    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));
    expect($upload->fresh()->status)->toBe('rejected')->and(AssetFile::count())->toBe(0);
});

it('rejects corrupted and changed upload contents permanently', function (): void {
    $upload = photoUpload(photoUser());
    Storage::disk('local')->put($upload->storage_key, str_repeat('x', $upload->byte_size));
    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));
    expect($upload->fresh()->status)->toBe('rejected')->and(AssetFile::count())->toBe(0);
});

it('never falls back to clean when scanner fails and can retry processing', function (): void {
    $user = photoUser();
    $upload = photoUpload($user);
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scan')->once()->andThrow(new RuntimeException('simulated unavailable scanner'));
    $job = new ProcessUpload($upload->id);
    expect(fn () => $job->handle(new ImageProcessor($scanner)))->toThrow(RuntimeException::class);
    expect($upload->fresh()->status)->toBe('queued')->and(AssetFile::count())->toBe(0);
    $job->failed(new RuntimeException('exhausted'));
    expect($upload->fresh()->status)->toBe('failed');
    $this->actingAs($user)->post('/admin/assets/'.$upload->asset_id.'/uploads/'.$upload->id.'/retry')->assertRedirect();
    $job->handle(app(ImageProcessor::class));
    expect($upload->fresh()->status)->toBe('completed')->and($upload->fresh()->attempts)->toBe(2);
    expect(AssetAuditEvent::query()->where('event_type', 'upload.retried')->count())->toBe(1);
});

it('rejects infected files before decoding or creating previews', function (): void {
    $upload = photoUpload(photoUser());
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scan')->once()->andThrow(ValidationException::withMessages(['files' => 'Malware gevonden.']));
    (new ProcessUpload($upload->id))->handle(new ImageProcessor($scanner));
    expect($upload->fresh()->status)->toBe('rejected')->and(AssetFile::count())->toBe(0);
    expect(Storage::disk('local')->allFiles('derivatives'))->toBe([]);
});

it('distinguishes scanned clean from public permission', function (): void {
    $upload = photoUpload(photoUser());
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scan')->once()->andReturn('clean');
    (new ProcessUpload($upload->id))->handle(new ImageProcessor($scanner));
    $file = AssetFile::query()->sole();
    expect($file->scanner_status)->toBe('clean')->and($file->scanned_at)->not->toBeNull()->and($file->publishable_at)->toBeNull();
});

it('does not steal an active claim and allows explicit stale recovery', function (): void {
    $user = photoUser();
    $upload = photoUpload($user);
    $upload->update(['status' => 'running', 'started_at' => now(), 'claim_token' => 'existing']);
    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));
    expect(AssetFile::count())->toBe(0);
    $this->actingAs($user)->post('/admin/assets/'.$upload->asset_id.'/uploads/'.$upload->id.'/retry')->assertConflict();
    $upload->update(['started_at' => now()->subMinutes(5)]);
    $this->post('/admin/assets/'.$upload->asset_id.'/uploads/'.$upload->id.'/retry')->assertRedirect();
    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));
    expect($upload->fresh()->status)->toBe('completed');
});

it('resumes after committed file persistence without creating another original', function (): void {
    $upload = photoUpload(photoUser());
    app(ImageProcessor::class)->process($upload);
    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));
    expect(AssetFile::count())->toBe(1)->and($upload->fresh()->status)->toBe('completed');
});

it('denies private list detail edit retry and media to a different volunteer', function (): void {
    $owner = photoUser('volunteer');
    $upload = photoUpload($owner);
    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));
    $file = AssetFile::query()->sole();
    $path = '/admin/assets/'.$upload->asset_id;
    $this->actingAs(photoUser('volunteer'))->get('/admin/assets')->assertOk()->assertDontSee('Original title');
    $this->get($path)->assertForbidden();
    $this->put($path, photoMetadata())->assertForbidden();
    $this->post($path.'/uploads/'.$upload->id.'/retry')->assertForbidden();
    $this->get($path.'/files/'.$file->id.'/media/preview300')->assertForbidden();
    $this->actingAs($owner)->get($path)->assertOk();
    $this->put($path, photoMetadata())->assertRedirect($path);
});

it('requires view permission and restricts viewers to read only', function (): void {
    $viewer = photoUser('viewer');
    $upload = photoUpload($viewer);
    $this->actingAs($viewer)->get('/admin/assets/'.$upload->asset_id)->assertOk();
    $this->postJson('/admin/assets', ['files' => [UploadedFile::fake()->image('x.png')]])->assertForbidden();
    $this->put('/admin/assets/'.$upload->asset_id, photoMetadata())->assertForbidden();
    $viewer->roles()->detach();
    $this->actingAs($viewer->fresh())->get('/admin/assets')->assertForbidden();
});

it('paginates without skipping the 26th photo and filters titles', function (): void {
    $user = photoUser();
    for ($i = 0; $i < 27; $i++) {
        Asset::query()->create(['accession_number' => (string) str()->ulid(), 'title' => 'Unique-'.$i, 'created_by_user_id' => $user->id]);
    }
    $first = $this->actingAs($user)->get('/admin/assets')->assertOk();
    $firstIds = $first->viewData('assets')->pluck('id');
    $second = $this->get('/admin/assets?cursor='.$first->viewData('nextCursor'))->assertOk();
    expect($firstIds->merge($second->viewData('assets')->pluck('id'))->unique())->toHaveCount(27);
    $this->get('/admin/assets?q=unique-26')->assertOk()->assertSee('Unique-26')->assertDontSee('Unique-25');
});

it('audits metadata rights and tags atomically and prevents stale overwrites', function (): void {
    $user = photoUser();
    $upload = photoUpload($user);
    $path = '/admin/assets/'.$upload->asset_id;
    $this->actingAs($user)->put($path, photoMetadata(['date_precision' => 'year', 'date_earliest' => '1923-06-15', 'rights_status' => 'verified']))->assertRedirect($path);
    $asset = Asset::findOrFail($upload->asset_id);
    expect($asset->lock_version)->toBe(2)->and($asset->date_earliest->format('Y-m-d'))->toBe('1923-01-01')
        ->and($asset->date_latest->format('Y-m-d'))->toBe('1923-12-31')->and($asset->tags)->toHaveCount(2)
        ->and($asset->rights->sole()->verification_status)->toBe('verified')->and($asset->catalogue_status)->toBe('draft');
    $event = AssetAuditEvent::query()->where('event_type', 'metadata.updated')->sole();
    expect($event->details['before']['metadata']['title'])->toBe('Original title')
        ->and($event->details['after']['metadata']['title'])->toBe('Straatbeeld');
    $this->from($path)->put($path, photoMetadata(['title' => 'Overwrite']))->assertOk()->assertViewIs('catalogue.conflict')->assertSee('Overwrite');
    expect($asset->fresh()->title)->toBe('Straatbeeld')->and(AssetAuditEvent::query()->where('event_type', 'metadata.updated')->count())->toBe(1);
    $this->get($path)->assertOk()->assertSee('Revisie 2')->assertSee('Straatbeeld')->assertSee('metadata.updated');
});

it('keeps historical calendar dates unchanged in revisions across timezones', function (string $timezone): void {
    $originalTimezone = date_default_timezone_get();
    date_default_timezone_set($timezone);
    try {
        $user = photoUser();
        $asset = Asset::query()->create([
            'accession_number' => (string) str()->ulid(), 'created_by_user_id' => $user->id,
            'title' => 'Before', 'date_precision' => 'year',
            'date_earliest' => '1901-01-01', 'date_latest' => '1901-12-31',
        ]);
        $this->actingAs($user)->put('/admin/assets/'.$asset->id, photoMetadata([
            'date_precision' => 'exact', 'date_earliest' => '1923-01-01',
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $details = $asset->auditEvents()->sole()->details;
        expect($details['before']['metadata']['date_earliest'])->toBe('1901-01-01')
            ->and($details['before']['metadata']['date_latest'])->toBe('1901-12-31')
            ->and($details['after']['metadata']['date_earliest'])->toBe('1923-01-01')
            ->and($details['after']['metadata']['date_latest'])->toBe('1923-01-01')
            ->and($asset->fresh()->toArray()['date_earliest'])->toBe('1923-01-01');
    } finally {
        date_default_timezone_set($originalTimezone);
    }
})->with(['Europe/Amsterdam', 'Pacific/Auckland', 'America/New_York']);

it('retries storage warnings after cleaning partial derivatives without rejecting a valid photo', function (): void {
    $upload = photoUpload(photoUser());
    $disk = Storage::disk('local');
    $original = $disk->get($upload->storage_key);
    $failingDisk = Mockery::mock($disk);
    $failingDisk->shouldReceive('put')->once()->with('derivatives/'.$upload->id.'/preview-300.jpg', Mockery::type('string'), Mockery::type('array'))
        ->andReturnUsing(fn ($key, $bytes, $options) => $disk->put($key, $bytes, $options));
    $failingDisk->shouldReceive('put')->once()->with('derivatives/'.$upload->id.'/preview-1200.jpg', Mockery::type('string'), Mockery::type('array'))
        ->andReturnUsing(function (): never {
            trigger_error('Simulated private storage outage.', E_USER_WARNING);
            throw new RuntimeException('Storage warning was not surfaced.');
        });
    Storage::shouldReceive('disk')->with('local')->once()->andReturn($failingDisk);
    set_error_handler(fn (): never => throw new RuntimeException('Simulated storage warning handler.'));
    try {
        expect(fn () => (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class)))->toThrow(RuntimeException::class);
    } finally {
        restore_error_handler();
    }
    expect($upload->fresh()->status)->toBe('queued')->and(AssetFile::count())->toBe(0)
        ->and($disk->allFiles('derivatives/'.$upload->id))->toBe([])
        ->and($disk->get($upload->storage_key))->toBe($original);
    Storage::shouldReceive('disk')->with('local')->once()->andReturn($disk);
    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));
    expect($upload->fresh()->status)->toBe('completed')->and(AssetFile::count())->toBe(1);
});

it('rejects inconsistent dates and oversized tags without losing metadata', function (array $data): void {
    $user = photoUser();
    $upload = photoUpload($user);
    $this->actingAs($user)->putJson('/admin/assets/'.$upload->asset_id, photoMetadata($data))->assertUnprocessable();
    expect(Asset::findOrFail($upload->asset_id)->lock_version)->toBe(1);
})->with([
    [['date_precision' => 'unknown', 'date_earliest' => '1923-01-01']],
    [['date_precision' => 'range', 'date_earliest' => '1923-01-01']],
    [['date_precision' => 'range', 'date_earliest' => '1923-01-01', 'date_latest' => '1922-01-01']],
    [['date_precision' => 'exact', 'date_earliest' => '1923-02-30']],
    [['date_precision' => 'exact', 'date_earliest' => '1923-01-01', 'date_latest' => '1923-01-02']],
    [['tags' => str_repeat('a', 101)]],
    [['tags' => implode(',', range(1, 21))]],
]);

it('cleans private objects and empty assets when queue persistence fails', function (): void {
    Queue::shouldReceive('connection')->with('ingest')->andReturnSelf();
    Queue::shouldReceive('push')->andThrow(new RuntimeException('queue write failure'));
    $this->actingAs(photoUser())->postJson('/admin/assets', ['files' => [UploadedFile::fake()->image('x.png')]])
        ->assertUnprocessable()->assertJsonPath('results.0.ok', false);
    expect(QuarantineUpload::count())->toBe(0)->and(Asset::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('corrects JPEG orientation and strips embedded EXIF from every preview', function (): void {
    $user = photoUser();
    $jpeg = UploadedFile::fake()->image('portrait.jpg', 80, 40);
    $bytes = file_get_contents($jpeg->getRealPath());
    // Little-endian TIFF IFD0 with orientation 6 (90 degrees clockwise).
    $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVvvV', 0x0112, 3, 1, 6, 0, 0);
    $oriented = substr($bytes, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($bytes, 2);
    $jpeg = UploadedFile::fake()->createWithContent('portrait.jpg', $oriented);
    $asset = Asset::query()->create(['accession_number' => (string) str()->ulid(), 'created_by_user_id' => $user->id]);
    $upload = app(QuarantineUploadService::class)->quarantine($asset, $jpeg, $user->id);
    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));
    expect($upload->fresh()->status)->toBe('completed');
    $file = AssetFile::query()->sole();
    expect($file->technical_metadata['orientation'])->toBe(6);
    foreach ($file->derivatives as $key) {
        $preview = Storage::disk('local')->get($key);
        $info = getimagesizefromstring($preview);
        expect($info[0])->toBe(40)->and($info[1])->toBe(80)->and($preview)->not->toContain("Exif\0\0");
    }
    expect(Storage::disk('local')->get($file->storage_key))->toBe($oriented);
});

it('escapes user metadata and keeps private file ownership independent of route asset', function (): void {
    $user = photoUser();
    $upload = photoUpload($user);
    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));
    $other = photoUpload($user, 'other.png', 60, 60);
    $file = AssetFile::query()->sole();
    $this->actingAs($user)->get('/admin/assets/'.$other->asset_id.'/files/'.$file->id.'/media/preview300')->assertNotFound();
    $this->put('/admin/assets/'.$upload->asset_id, photoMetadata(['title' => '<script>alert(1)</script>']))->assertRedirect();
    $this->get('/admin/assets/'.$upload->asset_id)->assertOk()
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
});
