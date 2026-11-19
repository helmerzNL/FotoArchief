<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Jobs\RebuildDerivativesJob;
use App\Modules\ArchiveOperations\Jobs\RunArchiveBackupJob;
use App\Modules\ArchiveOperations\Models\BackupJob;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Services\ArchiveBackupJobService;
use App\Modules\ArchiveOperations\Services\ArchiveBackupSchema;
use App\Modules\ArchiveOperations\Support\StoredZipWriter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

// App-Guidance shared/guidelines/backup-restore.md, as adapted for FotoArchief.

uses(RefreshDatabase::class);

function bkUser(string $roleKey = 'administrator', ?string $email = null): User
{
    app(DatabaseSeeder::class)->run();
    $user = User::query()->create([
        'name' => ucfirst($roleKey),
        'email' => $email ?? str()->uuid().'@example.test',
        'password' => Hash::make('a-secure-test-password'),
    ]);
    $user->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

    return $user;
}

function bkId(): string
{
    return strtolower((string) str()->ulid());
}

/** A small archive that touches links, self-references and optional links between sections. */
function bkSeedArchive(User $admin, string $bytes = 'original-bytes-one'): array
{
    $now = now();
    $asset = bkId();
    $file = bkId();
    $parentPlace = bkId();
    $place = bkId();
    $tag = bkId();
    $person = bkId();
    $collection = bkId();
    $publication = bkId();
    $worklist = bkId();
    Storage::disk('local')->put('quarantine/a/original', $bytes);
    foreach (['300', '1200', '2000'] as $size) {
        Storage::disk('local')->put("derivatives/{$file}/preview-{$size}.jpg", 'preview-'.$size);
    }
    DB::table('assets')->insert(['id' => $asset, 'accession_number' => 'FA-1', 'title' => 'Molen bij avond', 'created_by_user_id' => $admin->id, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('asset_files')->insert([
        'id' => $file, 'asset_id' => $asset, 'storage_disk' => 'local', 'storage_key' => 'quarantine/a/original',
        'sha256' => hash('sha256', $bytes), 'media_type' => 'image/jpeg', 'byte_size' => strlen($bytes), 'original_filename' => 'molen.jpg',
        'derivatives' => json_encode(['preview300' => "derivatives/{$file}/preview-300.jpg", 'preview1200' => "derivatives/{$file}/preview-1200.jpg", 'preview2000' => "derivatives/{$file}/preview-2000.jpg"]),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('asset_versions')->insert(['id' => bkId(), 'asset_id' => $asset, 'asset_file_id' => $file, 'version_number' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('locations')->insert([
        ['id' => $parentPlace, 'name' => 'Gelderland', 'normalized_name' => 'gelderland', 'parent_id' => null, 'created_at' => $now, 'updated_at' => $now],
        ['id' => $place, 'name' => 'Zutphen', 'normalized_name' => 'zutphen', 'parent_id' => $parentPlace, 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('asset_locations')->insert(['id' => bkId(), 'asset_id' => $asset, 'location_id' => $place, 'relationship_type' => 'depicted', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('tags')->insert(['id' => $tag, 'name' => 'Molen', 'slug' => 'molen', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('asset_tags')->insert(['id' => bkId(), 'asset_id' => $asset, 'tag_id' => $tag, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('people')->insert(['id' => $person, 'display_name' => 'Anna Jansen', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('asset_people')->insert(['id' => bkId(), 'asset_id' => $asset, 'person_id' => $person, 'relationship_type' => 'depicted', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('collections')->insert(['id' => $collection, 'title' => 'Molens', 'slug' => 'molens', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('collection_assets')->insert(['id' => bkId(), 'collection_id' => $collection, 'asset_id' => $asset, 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('publications')->insert(['id' => $publication, 'asset_id' => $asset, 'requested_by_user_id' => $admin->id, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('worklists')->insert(['id' => $worklist, 'title' => 'Te beschrijven', 'created_by_user_id' => $admin->id, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('worklist_items')->insert(['id' => bkId(), 'worklist_id' => $worklist, 'asset_id' => $asset, 'created_at' => $now, 'updated_at' => $now]);

    return compact('asset', 'file', 'tag', 'place', 'parentPlace', 'worklist');
}

/** Every section, rows sorted by id: what a round trip must give back. */
function bkSnapshot(): array
{
    $snapshot = [];
    foreach (app(ArchiveBackupSchema::class)->sections() as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    return $snapshot;
}

/** Runs the queued steps the way the worker does, until the job is finished. */
function bkRun(): ?BackupJob
{
    for ($i = 0; $i < 200; $i++) {
        $job = BackupJob::query()->where('status', BackupJob::STATUS_RUNNING)->first();
        if ($job === null) {
            break;
        }
        (new RunArchiveBackupJob($job->id))->handle(app(ArchiveBackupJobService::class));
    }

    return BackupJob::query()->orderByDesc('created_at')->first();
}

/** Makes a backup through the routes and returns the path of the downloaded file. */
function bkExport(object $test, User $admin, bool $withFiles): string
{
    $test->actingAs($admin)->postJson(route('admin.operations.backup.export'), ['with_files' => $withFiles])->assertAccepted();
    $job = bkRun();
    expect($job->status)->toBe('done');
    $response = $test->actingAs($admin)->get(route('admin.operations.backup.download', $job))->assertOk();
    $copy = test()->backupRoot.'/download-'.bkId().($withFiles ? '.zip' : '.json');
    copy($response->getFile()->getPathname(), $copy);

    return $copy;
}

/** Uploads a file in chunks, as the screen does, and returns the check's answer. */
function bkStage(object $test, User $admin, string $path, int $chunk = 256): TestResponse
{
    $bytes = (string) file_get_contents($path);
    $token = $test->actingAs($admin)->postJson(route('admin.operations.backup.stage'), ['size' => strlen($bytes)])->assertOk()->json('token');
    for ($offset = 0; $offset < strlen($bytes); $offset += $chunk) {
        $test->actingAs($admin)->call('PUT', route('admin.operations.backup.stage.chunk', $token).'?offset='.$offset, [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], substr($bytes, $offset, $chunk))->assertOk();
    }

    return $test->actingAs($admin)->postJson(route('admin.operations.backup.stage.check', $token));
}

function bkRestore(object $test, User $admin, string $path): BackupJob
{
    $token = bkStage($test, $admin, $path)->assertOk()->json('token');
    $test->actingAs($admin)->postJson(route('admin.operations.backup.restore'), ['token' => $token])->assertAccepted();

    return bkRun();
}

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
    $this->backupRoot = sys_get_temp_dir().'/fotoarchief-backup-test-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->backupRoot);
    config([
        'filesystems.default' => 'local',
        'operations.backup.staging_path' => $this->backupRoot.'/staging',
        'operations.backup.auto.path' => $this->backupRoot.'/auto',
        'operations.backup.auto.enabled' => false,
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->backupRoot);
});

it('lets only a signed-in administrator use any backup route', function (): void {
    $viewer = bkUser('viewer');
    $job = BackupJob::query()->create(['kind' => 'export', 'status' => 'done', 'includes_files' => false]);

    $this->get(route('admin.operations.backup'))->assertRedirect('/login');
    $this->actingAs($viewer)->get(route('admin.operations.backup'))->assertForbidden();
    $this->actingAs($viewer)->postJson(route('admin.operations.backup.export'), ['with_files' => false])->assertForbidden();
    $this->actingAs($viewer)->postJson(route('admin.operations.backup.stage'), ['size' => 10])->assertForbidden();
    $this->actingAs($viewer)->getJson(route('admin.operations.backup.latest'))->assertForbidden();
    $this->actingAs($viewer)->postJson(route('admin.operations.backup.restore'), ['token' => str_repeat('a', 32)])->assertForbidden();
    $this->actingAs($viewer)->get(route('admin.operations.backup.download', $job))->assertForbidden();
    expect(BackupJob::query()->count())->toBe(1);
});

it('shows the backup page with its progress block to an administrator', function (): void {
    $admin = bkUser();
    $this->actingAs($admin)->get(route('admin.operations.backup'))
        ->assertOk()
        ->assertSee('data-backup-progress', false)
        ->assertSee(__('operations.backup.make_with_files'))
        ->assertSee(__('operations.backup.auto_off'));
});

it('writes a metadata backup that holds the archive and nothing that unlocks the app', function (): void {
    $admin = bkUser();
    bkSeedArchive($admin);
    DB::table('user_invitations')->insert(['id' => bkId(), 'email' => 'x@example.test', 'name' => 'X', 'token_hash' => str_repeat('f', 64), 'role_ids' => '[]', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

    $path = bkExport($this, $admin, false);
    $text = (string) file_get_contents($path);
    $document = json_decode($text, true);

    expect($document['format'])->toBe('fotoarchief-archive-backup')
        ->and($document['format_version'])->toBe(1)
        ->and($document['includes_files'])->toBeFalse()
        ->and($document['sections']['assets'][0]['title'])->toBe('Molen bij avond')
        ->and($document['sections'])->not->toHaveKey('users')
        ->and($document)->not->toHaveKey('files');
    foreach (['password', 'remember_token', 'credential_public_key', 'token_hash', 'code_hash', 'recovery', 'passkey', 'api_key', 'access_token', 'refresh_token', $admin->email] as $word) {
        expect(stripos($text, $word))->toBeFalse("the backup contains {$word}");
    }
});

it('writes a ZIP with backup.json and every original loose and unchanged under files/', function (): void {
    $admin = bkUser();
    $seed = bkSeedArchive($admin, 'first-original');
    // A second original with the same name gets " (2)".
    $second = bkId();
    Storage::disk('local')->put('quarantine/b/original', 'second-original');
    DB::table('asset_files')->insert(['id' => $second, 'asset_id' => $seed['asset'], 'storage_disk' => 'local', 'storage_key' => 'quarantine/b/original', 'sha256' => hash('sha256', 'second-original'), 'media_type' => 'image/jpeg', 'byte_size' => 15, 'original_filename' => 'molen.jpg', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()]);

    $zip = new ZipArchive;
    expect($zip->open(bkExport($this, $admin, true)))->toBeTrue();
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $document = json_decode((string) $zip->getFromName('backup.json'), true);

    expect($names)->toBe(['backup.json', 'files/molen.jpg', 'files/molen (2).jpg'])
        ->and($zip->getFromName('files/molen.jpg'))->toBe('first-original')
        ->and($zip->getFromName('files/molen (2).jpg'))->toBe('second-original')
        ->and($zip->statName('files/molen.jpg')['comp_method'])->toBe(ZipArchive::CM_STORE)
        ->and($document['includes_files'])->toBeTrue()
        ->and($document['files'][$seed['file']])->toBe(['archive_path' => 'files/molen.jpg', 'sha256' => hash('sha256', 'first-original'), 'byte_size' => 14]);
    $zip->close();
});

it('restores what was backed up, replacing what is there, through stage, confirm and the job', function (): void {
    $admin = bkUser();
    $seed = bkSeedArchive($admin);
    $before = bkSnapshot();
    $path = bkExport($this, $admin, true);

    // Change everything after the backup.
    DB::table('tags')->where('id', $seed['tag'])->update(['name' => 'Gewijzigd']);
    DB::table('assets')->insert(['id' => bkId(), 'accession_number' => 'FA-NEW', 'title' => 'Na de back-up', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('integrity_checks')->insert(['id' => bkId(), 'asset_id' => $seed['asset'], 'asset_file_id' => $seed['file'], 'status' => 'ok', 'created_at' => now(), 'updated_at' => now()]);

    $summary = bkStage($this, $admin, $path)->assertOk()->json('summary');
    expect($summary['counts']['assets'])->toBe(1)->and($summary['includes_files'])->toBeTrue();
    expect(bkSnapshot())->not->toBe($before); // staging changed nothing

    $job = bkRestore($this, $admin, $path);

    expect($job->status)->toBe('done')
        ->and($job->checking_seconds)->not->toBeNull()
        ->and($job->replacing_seconds)->not->toBeNull()
        ->and(bkSnapshot())->toBe($before)
        ->and(DB::table('integrity_checks')->count())->toBe(0)
        ->and(Storage::disk('local')->get('quarantine/a/original'))->toBe('original-bytes-one');
    // The same installation: the identical original was used, not stored twice.
    expect(Storage::disk('local')->allFiles('quarantine'))->toBe(['quarantine/a/original']);
});

it('restores onto another installation: originals are stored again and previews are queued to be made', function (): void {
    $admin = bkUser();
    $seed = bkSeedArchive($admin);
    $path = bkExport($this, $admin, true);
    Storage::fake('local'); // an empty storage, as on a new server

    $job = bkRestore($this, $admin, $path);

    $file = DB::table('asset_files')->where('id', $seed['file'])->first();
    expect($job->status)->toBe('done')
        ->and($file->storage_key)->not->toBe('quarantine/a/original')
        ->and($file->storage_key)->toStartWith('quarantine/')
        ->and(Storage::disk('local')->get($file->storage_key))->toBe('original-bytes-one')
        ->and($file->derivatives)->toBeNull()
        ->and(DB::table('integrity_checks')->where('asset_file_id', $seed['file'])->where('status', 'missing_derivative')->whereNull('resolved_at')->count())->toBe(1)
        ->and(OperationRun::query()->where('operation_type', RebuildDerivativesJob::TYPE)->count())->toBe(1);
});

it('empties links to people who are not here, and gives a required owner to the administrator who restores', function (): void {
    $admin = bkUser();
    $other = bkUser('editor');
    $seed = bkSeedArchive($other);
    $path = bkExport($this, $admin, true);
    DB::table('worklists')->delete();
    DB::table('publications')->delete();
    DB::table('users')->where('id', $other->id)->delete();

    bkRestore($this, $admin, $path);

    expect(DB::table('assets')->where('id', $seed['asset'])->value('created_by_user_id'))->toBeNull()
        ->and(DB::table('worklists')->where('id', $seed['worklist'])->value('created_by_user_id'))->toBe($admin->id)
        ->and(DB::table('locations')->where('id', $seed['place'])->value('parent_id'))->toBe($seed['parentPlace']);
});

it('refuses a metadata backup whose originals are not in storage here, and changes nothing', function (): void {
    $admin = bkUser();
    bkSeedArchive($admin);
    $path = bkExport($this, $admin, false);
    DB::table('tags')->update(['name' => 'Na de back-up']);
    $before = bkSnapshot();
    Storage::disk('local')->delete('quarantine/a/original');

    $job = bkRestore($this, $admin, $path);

    expect($job->status)->toBe('failed')
        ->and(app(ArchiveBackupJobService::class)->present($job)['error'])->toContain('molen.jpg')
        ->and(bkSnapshot())->toBe($before)
        ->and(glob(config('operations.backup.staging_path').'/.restore-*'))->toBe([]);
});

it('refuses junk, a newer version and a broken link at the check, and keeps nothing it refused', function (): void {
    $admin = bkUser();
    bkSeedArchive($admin);
    $path = bkExport($this, $admin, false);
    $document = json_decode((string) file_get_contents($path), true);

    file_put_contents($junk = $this->backupRoot.'/junk.json', 'this is not a backup');
    bkStage($this, $admin, $junk)->assertUnprocessable()->assertJsonPath('message', __('operations.backup.errors.not_a_backup'));

    $newer = $document;
    $newer['app_version'] = '99.0.0';
    file_put_contents($newerPath = $this->backupRoot.'/newer.json', json_encode($newer));
    expect(bkStage($this, $admin, $newerPath)->assertUnprocessable()->json('message'))->toContain('99.0.0');

    $broken = $document;
    $broken['sections']['asset_tags'][0]['tag_id'] = bkId();
    file_put_contents($brokenPath = $this->backupRoot.'/broken.json', json_encode($broken));
    bkStage($this, $admin, $brokenPath)->assertUnprocessable();

    $edited = $document;
    $edited['sections']['assets'][0]['not_a_column'] = 'x';
    file_put_contents($editedPath = $this->backupRoot.'/edited.json', json_encode($edited));
    bkStage($this, $admin, $editedPath)->assertUnprocessable();

    expect(glob(config('operations.backup.staging_path').'/.restore-*'))->toBe([]);
});

it('refuses a ZIP whose original was edited, at the job, and changes nothing', function (): void {
    $admin = bkUser();
    bkSeedArchive($admin, 'the-real-bytes');
    $path = bkExport($this, $admin, true);
    DB::table('tags')->update(['name' => 'Na de back-up']);
    $before = bkSnapshot();
    Storage::fake('local');

    // Same size, different bytes: the size check passes, the checksum does not.
    $zip = new ZipArchive;
    $zip->open($path);
    $document = (string) $zip->getFromName('backup.json');
    $zip->close();
    $edited = $this->backupRoot.'/edited.zip';
    $writer = StoredZipWriter::open($edited);
    $json = fopen('php://memory', 'w+b');
    fwrite($json, $document);
    rewind($json);
    $writer->addStream('backup.json', $json, null, true);
    $member = fopen('php://memory', 'w+b');
    fwrite($member, 'the-fake-bytes');
    rewind($member);
    $writer->addStream('files/molen.jpg', $member, 14);
    $writer->finish();

    $job = bkRestore($this, $admin, $edited);

    expect($job->status)->toBe('failed')
        ->and(app(ArchiveBackupJobService::class)->present($job)['error'])->toContain(__('operations.backup.errors.file_damaged', ['file' => 'files/molen.jpg']))
        ->and(bkSnapshot())->toBe($before)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('runs one job at a time, gives up a job that stopped moving and reports a finished job once', function (): void {
    $admin = bkUser();
    $running = BackupJob::query()->create(['kind' => 'restore', 'status' => 'running', 'phase' => 'replacing', 'includes_files' => false, 'state' => ['token' => str_repeat('b', 32)]]);

    $this->actingAs($admin)->postJson(route('admin.operations.backup.export'), ['with_files' => false])->assertStatus(409);

    BackupJob::query()->whereKey($running->id)->update(['updated_at' => now()->subMinutes(20)]);
    $latest = $this->actingAs($admin)->getJson(route('admin.operations.backup.latest'))->assertOk()->json('job');
    expect($latest['status'])->toBe('failed')
        ->and($latest['error'])->toBe(__('operations.backup.errors.interrupted_restore'));

    $this->actingAs($admin)->deleteJson(route('admin.operations.backup.dismiss', $running))->assertOk();
    $this->actingAs($admin)->getJson(route('admin.operations.backup.latest'))->assertOk()->assertJsonPath('job', null);
    $this->actingAs($admin)->postJson(route('admin.operations.backup.export'), ['with_files' => false])->assertAccepted();
});

it('makes automatic backups on schedule, keeps the newest, and never backs up nothing', function (): void {
    $admin = bkUser();
    bkSeedArchive($admin);
    $service = app(ArchiveBackupJobService::class);
    $auto = config('operations.backup.auto.path');
    File::ensureDirectoryExists($auto);
    file_put_contents($auto.'/fotoarchief-auto-2020-01-01-000000Z.zip', 'old');
    file_put_contents($auto.'/fotoarchief-auto-2020-01-02-000000Z.zip', 'older');
    file_put_contents($auto.'/notes.txt', 'not a backup');

    expect($service->runAutomaticIfDue())->toBeNull(); // off by default

    config(['operations.backup.auto.enabled' => true, 'operations.backup.auto.keep' => 2]);
    expect($service->runAutomaticIfDue())->toBeInstanceOf(BackupJob::class);
    expect(bkRun()->status)->toBe('done');
    $names = array_column($service->automaticBackups(), 'name');
    expect($names)->toHaveCount(2)
        ->and($names[1])->toBe('fotoarchief-auto-2020-01-02-000000Z.zip')
        ->and($names[0])->toMatch(ArchiveBackupJobService::AUTO_PATTERN)
        ->and(file_exists($auto.'/notes.txt'))->toBeTrue()
        ->and(glob($auto.'/.tmp-*'))->toBe([]);

    $zip = new ZipArchive;
    $zip->open($auto.'/'.$names[0]);
    expect($zip->getFromName('files/molen.jpg'))->toBe('original-bytes-one');
    $zip->close();

    // Not due again before the interval.
    expect($service->runAutomaticIfDue())->toBeNull();

    // No backup while nobody can administer the archive.
    $this->travel(13)->hours();
    DB::table('users')->update(['is_active' => false]);
    expect($service->runAutomaticIfDue())->toBeNull();
});

it('shows a failed automatic backup and retries it only after the delay', function (): void {
    $admin = bkUser();
    config(['operations.backup.auto.enabled' => true]);
    $service = app(ArchiveBackupJobService::class);
    BackupJob::query()->create(['kind' => 'auto', 'status' => 'failed', 'includes_files' => true, 'error_key' => 'operations.backup.errors.disk_full', 'error_params' => [], 'finished_at' => now()]);

    expect($service->runAutomaticIfDue())->toBeNull();
    $this->actingAs($admin)->get(route('admin.operations.backup'))->assertOk()->assertSee(__('operations.backup.errors.disk_full'));

    $this->travel(61)->minutes();
    expect($service->runAutomaticIfDue())->toBeInstanceOf(BackupJob::class);
});

it('restores a kept automatic backup through a staged copy and leaves the kept file as it was', function (): void {
    $admin = bkUser();
    bkSeedArchive($admin);
    config(['operations.backup.auto.enabled' => true]);
    $service = app(ArchiveBackupJobService::class);
    $service->runAutomaticIfDue();
    bkRun();
    $name = $service->automaticBackups()[0]['name'];
    $kept = (string) file_get_contents(config('operations.backup.auto.path').'/'.$name);
    DB::table('tags')->update(['name' => 'Na de back-up']);

    $token = $this->actingAs($admin)->postJson(route('admin.operations.backup.auto.stage', $name))->assertOk()->json('token');
    $this->actingAs($admin)->postJson(route('admin.operations.backup.restore'), ['token' => $token])->assertAccepted();
    expect(bkRun()->status)->toBe('done')
        ->and(DB::table('tags')->value('name'))->toBe('Molen')
        ->and(file_get_contents(config('operations.backup.auto.path').'/'.$name))->toBe($kept);

    $this->actingAs($admin)->get(route('admin.operations.backup.auto.download', $name))->assertOk();
    $this->actingAs($admin)->get('/admin/operations/backup/auto/fotoarchief-auto-..-x.zip')->assertNotFound();
});

it('writes a ZIP across steps that libzip reads, and cuts off a step that died halfway', function (): void {
    $path = $this->backupRoot.'/steps.zip';
    $stream = function (string $bytes) {
        $handle = fopen('php://memory', 'w+b');
        fwrite($handle, $bytes);
        rewind($handle);

        return $handle;
    };
    $writer = StoredZipWriter::open($path);
    $writer->addStream('backup.json', $stream(str_repeat('{"a":1}', 1000)), null, true);
    $writer->close();

    // A step that died after writing half an entry.
    file_put_contents($path, 'half-written-garbage', FILE_APPEND);
    $writer = StoredZipWriter::open($path);
    $writer->addStream('files/één.jpg', $stream('photo-bytes'), 11);
    expect(fn () => $writer->addStream('files/wrong.jpg', $stream('short'), 99))->toThrow(RuntimeException::class);
    $writer->finish();

    $zip = new ZipArchive;
    expect($zip->open($path, ZipArchive::CHECKCONS))->toBeTrue()
        ->and($zip->numFiles)->toBe(2)
        ->and($zip->getFromName('backup.json'))->toBe(str_repeat('{"a":1}', 1000))
        ->and($zip->getFromName('files/één.jpg'))->toBe('photo-bytes');
    $zip->close();
});

it('keeps every archive table in the backup and never a credential table', function (): void {
    $schema = app(ArchiveBackupSchema::class);
    $sections = $schema->sections();
    $cleared = $schema->clearedTables();

    expect($sections)->toEqualCanonicalizing(array_values(array_filter(ArchiveBackupSchema::SECTIONS, fn (string $table): bool => Schema::hasTable($table))))
        ->and($schema->insertOrder())->toHaveCount(count($sections));
    foreach (['users', 'roles', 'role_user', 'user_passkeys', 'user_recovery_codes', 'user_invitations', 'webauthn_challenges', 'backup_jobs', 'ai_provider_configs', 'onedrive_connections'] as $table) {
        expect(in_array($table, $sections, true))->toBeFalse("{$table} must not be in a backup")
            ->and(in_array($table, $cleared, true))->toBeFalse("{$table} must not be cleared by a restore");
    }
});
