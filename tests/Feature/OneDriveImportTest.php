<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Services\OperationRunService;
use App\Modules\Ingest\Jobs\OneDriveImportJob;
use App\Modules\Ingest\Models\OneDriveConnection;
use App\Modules\Ingest\Models\OneDriveImportedItem;
use App\Modules\Ingest\Models\QuarantineUpload;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const ONEDRIVE_GRAPH = 'https://graph.microsoft.com/v1.0';
const ONEDRIVE_LOGIN = 'https://login.microsoftonline.com/consumers/oauth2/v2.0';
const ONEDRIVE_APP = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

function oneDriveUser(string $role = 'administrator'): User
{
    $user = User::query()->create(['name' => 'Beheer', 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

    return $user;
}

/** @param array<string, mixed> $overrides */
function connectedDrive(array $overrides = []): OneDriveConnection
{
    $connection = OneDriveConnection::current();
    $connection->forceFill($overrides + [
        'refresh_token' => 'refresh-token-value',
        'access_token' => 'access-token-value',
        'access_token_expires_at' => now()->addHour(),
        'account_name' => 'Historische Vereniging',
        'folder_item_id' => 'F1',
        'folder_name' => "Foto's",
        'folder_path' => "/Foto's",
    ])->save();

    return $connection;
}

/** @return array<string, mixed> */
function driveFile(string $id, string $name, int $size, string $parent, ?string $path = null): array
{
    return ['id' => $id, 'name' => $name, 'size' => $size, 'file' => ['mimeType' => 'application/octet-stream'], 'cTag' => 'c'.$id,
        'parentReference' => array_filter(['id' => $parent, 'path' => $path])];
}

function jpegBytes(): string
{
    $image = imagecreatetruecolor(20, 10);
    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

/** Runs the job chunk by chunk, as the queue would, until the run ends. */
function runOneDrive(User $user, string $mode, int $maxChunks = 10): OperationRun
{
    $run = app(OperationRunService::class)->dispatchRun(OneDriveImportJob::class, OneDriveImportJob::TYPE, $user, ['mode' => $mode, 'stats' => []]);
    for ($chunk = 1; $chunk <= $maxChunks && ! $run->refresh()->isFinished(); $chunk++) {
        (new OneDriveImportJob($run->id, $chunk))->handle();
    }

    return $run->refresh();
}

/**
 * A drive with a folder "Foto's" (F1) and a subfolder "Straten" (S1). The first delta
 * page holds folders, a JPEG with a path, a JPEG in the subfolder without a path (as
 * personal OneDrive delta does), an AVIF, a Word file, a deleted item and a file over
 * the size limit; the second page one more JPEG; the delta link then shows one new file.
 */
function fakeDrive(): void
{
    $jpeg = jpegBytes();
    Http::fake(function (Request $request) use ($jpeg) {
        $url = $request->url();
        if ($request->method() !== 'GET') {
            return Http::response(['error' => 'only GET is expected'], 500);
        }

        return match (true) {
            str_starts_with($url, ONEDRIVE_GRAPH.'/me/drive/items/F1/delta?token=page2') => Http::response([
                'value' => [driveFile('E', 'e.jpg', strlen($jpeg), 'S1')],
                '@odata.deltaLink' => ONEDRIVE_GRAPH.'/me/drive/items/F1/delta?token=delta1',
            ]),
            str_starts_with($url, ONEDRIVE_GRAPH.'/me/drive/items/F1/delta?token=delta1') => Http::response([
                'value' => [driveFile('G', 'g.jpg', strlen($jpeg), 'F1', "/drive/root:/Foto's"), driveFile('A', 'a.jpg', strlen($jpeg), 'F1', "/drive/root:/Foto's")],
                '@odata.deltaLink' => ONEDRIVE_GRAPH.'/me/drive/items/F1/delta?token=delta2',
            ]),
            str_starts_with($url, ONEDRIVE_GRAPH.'/me/drive/items/F1/delta') => Http::response([
                'value' => [
                    ['id' => 'F1', 'name' => "Foto's", 'folder' => ['childCount' => 1]],
                    ['id' => 'S1', 'name' => 'Straten', 'folder' => ['childCount' => 0], 'parentReference' => ['id' => 'F1']],
                    driveFile('A', 'a.jpg', strlen($jpeg), 'F1', "/drive/root:/Foto's"),
                    driveFile('B', 'b.jpg', strlen($jpeg), 'S1'),
                    driveFile('C', 'c.avif', 100, 'F1'),
                    driveFile('D', 'd.docx', 100, 'F1'),
                    ['id' => 'X', 'deleted' => ['state' => 'deleted']],
                    driveFile('L', 'groot.jpg', 999_999_999_999, 'F1'),
                ],
                '@odata.nextLink' => ONEDRIVE_GRAPH.'/me/drive/items/F1/delta?token=page2',
            ]),
            str_starts_with($url, ONEDRIVE_GRAPH.'/me/drive/items/S1?') => Http::response(['id' => 'S1', 'name' => 'Straten', 'parentReference' => ['path' => '/drive/root:/Foto%27s']]),
            preg_match('#/me/drive/items/\w+/content$#', $url) === 1 => Http::response($jpeg),
            default => Http::response([], 404),
        };
    });
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    config(['filesystems.default' => 'local', 'ingest.scanner' => 'none', 'ingest.onedrive.client_id' => ONEDRIVE_APP]);
    Storage::fake('local');
    Queue::fake();
});

it('imports supported files through the normal ingest with the folder path as provenance', function (): void {
    connectedDrive();
    fakeDrive();

    $run = runOneDrive(oneDriveUser(), 'import');

    expect($run->status)->toBe(OperationRun::STATUS_COMPLETED)
        ->and($run->processed_items)->toBe(3)
        ->and($run->result['complete'])->toBeTrue()
        ->and($run->result['stats'])->toMatchArray(['imported' => 3, 'unsupported' => 1, 'ignored' => 1, 'too_large' => 1, 'deleted' => 1])
        ->and(QuarantineUpload::query()->orderBy('original_path')->pluck('original_path')->all())
        ->toBe(["Foto's/Straten/b.jpg", "Foto's/Straten/e.jpg", "Foto's/a.jpg"])
        ->and(QuarantineUpload::query()->pluck('status')->unique()->all())->toBe(['queued'])
        ->and(OneDriveConnection::current()->delta_link)->toBe(ONEDRIVE_GRAPH.'/me/drive/items/F1/delta?token=delta1')
        ->and(OneDriveConnection::current()->resume_link)->toBeNull();
    // The subfolder's path is looked up once and then remembered.
    Http::assertSentCount(1 + 1 + 1 + 3);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

it('counts without storing anything or moving the import position', function (): void {
    connectedDrive();
    fakeDrive();

    $run = runOneDrive(oneDriveUser(), 'survey');

    expect($run->status)->toBe(OperationRun::STATUS_COMPLETED)
        ->and($run->result['stats'])->toMatchArray(['new' => 3, 'unsupported' => 1, 'too_large' => 1])
        ->and($run->result['stats']['types']['jpg']['files'])->toBe(4)
        ->and(QuarantineUpload::count())->toBe(0)
        ->and(OneDriveImportedItem::count())->toBe(0)
        ->and(OneDriveConnection::current()->delta_link)->toBeNull();
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/content'));
});

it('fetches only new files on a top-up and never imports a file twice', function (): void {
    connectedDrive();
    fakeDrive();
    $user = oneDriveUser();
    runOneDrive($user, 'import');

    $topUp = runOneDrive($user, 'import');

    expect($topUp->processed_items)->toBe(1)
        ->and($topUp->result['stats'])->toMatchArray(['imported' => 1, 'existing' => 1])
        ->and(QuarantineUpload::count())->toBe(4)
        ->and(OneDriveConnection::current()->delta_link)->toBe(ONEDRIVE_GRAPH.'/me/drive/items/F1/delta?token=delta2');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'token=delta1'));
});

it('keeps its position on the connection, so a stopped import continues where it was', function (): void {
    connectedDrive();
    fakeDrive();
    config(['ingest.onedrive.files_per_chunk' => 1]);
    $user = oneDriveUser();
    $run = app(OperationRunService::class)->dispatchRun(OneDriveImportJob::class, OneDriveImportJob::TYPE, $user, ['mode' => 'import', 'stats' => []]);

    (new OneDriveImportJob($run->id, 1))->handle();
    app(OperationRunService::class)->cancelRun($run->refresh());

    expect(QuarantineUpload::count())->toBe(1)
        ->and(OneDriveConnection::current()->resume_link)->toContain('/me/drive/items/F1/delta')
        ->and(OneDriveConnection::current()->resume_offset)->toBe(3);

    config(['ingest.onedrive.files_per_chunk' => 25]);
    $next = runOneDrive($user, 'import');

    expect($next->status)->toBe(OperationRun::STATUS_COMPLETED)
        ->and(QuarantineUpload::count())->toBe(3)
        ->and(OneDriveImportedItem::query()->where('item_id', 'A')->count())->toBe(1);
});

it('pauses while the ingest queue is full instead of piling up uploads', function (): void {
    connectedDrive();
    fakeDrive();
    config(['ingest.onedrive.max_pending' => 0]);
    $run = app(OperationRunService::class)->dispatchRun(OneDriveImportJob::class, OneDriveImportJob::TYPE, oneDriveUser(), ['mode' => 'import', 'stats' => []]);

    (new OneDriveImportJob($run->id, 1))->handle();

    expect($run->refresh()->status)->toBe(OperationRun::STATUS_QUEUED)
        ->and(QuarantineUpload::count())->toBe(0);
    Http::assertNothingSent();
    Queue::assertPushed(OneDriveImportJob::class, fn (OneDriveImportJob $job): bool => $job->chunkNumber === 2 && $job->delay === 60);
});

it('waits as long as Microsoft asks when it throttles', function (): void {
    connectedDrive();
    Http::fake([ONEDRIVE_GRAPH.'/*' => Http::response(['error' => ['code' => 'activityLimitReached']], 429, ['Retry-After' => '120'])]);
    $run = app(OperationRunService::class)->dispatchRun(OneDriveImportJob::class, OneDriveImportJob::TYPE, oneDriveUser(), ['mode' => 'import', 'stats' => []]);

    (new OneDriveImportJob($run->id, 1))->handle();

    expect($run->refresh()->status)->toBe(OperationRun::STATUS_QUEUED);
    Queue::assertPushed(OneDriveImportJob::class, fn (OneDriveImportJob $job): bool => $job->chunkNumber === 2 && $job->delay === 120);
});

it('renews the access token, stores the new refresh token and keeps both secret', function (): void {
    connectedDrive(['access_token' => null, 'access_token_expires_at' => null]);
    Http::fake([
        ONEDRIVE_LOGIN.'/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600]),
        ONEDRIVE_GRAPH.'/*' => Http::response(['value' => [], '@odata.deltaLink' => ONEDRIVE_GRAPH.'/me/drive/items/F1/delta?token=d']),
    ]);

    runOneDrive(oneDriveUser(), 'survey');

    $connection = OneDriveConnection::current();
    $raw = (array) DB::table('onedrive_connections')->first();
    expect($connection->refresh_token)->toBe('new-refresh')
        ->and($connection->access_token)->toBe('new-access')
        ->and(implode(' ', array_map('strval', $raw)))->not->toContain('new-refresh')
        ->and($connection->toArray())->not->toHaveKeys(['client_secret', 'refresh_token', 'access_token']);
    // A public client: the shared app's ID and never a secret.
    Http::assertSent(fn (Request $request): bool => $request->url() === ONEDRIVE_LOGIN.'/token'
        && $request['grant_type'] === 'refresh_token' && $request['scope'] === 'Files.Read offline_access'
        && $request['client_id'] === ONEDRIVE_APP && ! isset($request['client_secret']));
});

it('stops with a reconnect message when Microsoft no longer accepts the connection', function (): void {
    connectedDrive(['access_token' => null, 'access_token_expires_at' => null]);
    Http::fake([ONEDRIVE_LOGIN.'/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'refresh-token-value expired'], 400)]);
    $run = app(OperationRunService::class)->dispatchRun(OneDriveImportJob::class, OneDriveImportJob::TYPE, oneDriveUser(), ['mode' => 'import', 'stats' => []]);

    (new OneDriveImportJob($run->id, 1))->handle();

    expect($run->refresh()->status)->toBe(OperationRun::STATUS_FAILED)
        ->and($run->error_message)->toContain('Koppel OneDrive opnieuw')
        ->and($run->error_message)->not->toContain('refresh-token-value');
    // Only the first job of the run; no continuation and no retry.
    Queue::assertPushed(OneDriveImportJob::class, 1);
});

it('reads the folder again when the delta link has expired', function (): void {
    connectedDrive(['delta_link' => ONEDRIVE_GRAPH.'/me/drive/items/F1/delta?token=old']);
    Http::fake([
        ONEDRIVE_GRAPH.'/me/drive/items/F1/delta?token=old' => Http::response([], 410),
        ONEDRIVE_GRAPH.'/me/drive/items/F1/delta' => Http::response(['value' => [], '@odata.deltaLink' => ONEDRIVE_GRAPH.'/me/drive/items/F1/delta?token=new']),
    ]);

    $run = runOneDrive(oneDriveUser(), 'import');

    expect($run->status)->toBe(OperationRun::STATUS_COMPLETED)
        ->and(OneDriveConnection::current()->delta_link)->toBe(ONEDRIVE_GRAPH.'/me/drive/items/F1/delta?token=new');
});

it('never sends the access token to a link outside Microsoft Graph', function (): void {
    connectedDrive(['resume_link' => 'https://elders.example/delta']);
    Http::fake();
    $run = app(OperationRunService::class)->dispatchRun(OneDriveImportJob::class, OneDriveImportJob::TYPE, oneDriveUser(), ['mode' => 'import', 'stats' => []]);

    expect(fn () => (new OneDriveImportJob($run->id, 1))->handle())->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

/** A sign-in that is not connected yet; Microsoft answers the device code endpoint. */
function startDeviceLogin(User $admin, mixed $test, array $deviceAnswer = []): void
{
    OneDriveConnection::current();
    Http::fake([
        ONEDRIVE_LOGIN.'/devicecode' => Http::response($deviceAnswer + [
            'device_code' => 'secret-device-code', 'user_code' => 'ABCD-EFGH',
            'verification_uri' => 'https://www.microsoft.com/link', 'expires_in' => 900, 'interval' => 5,
        ]),
    ]);
    $test->actingAs($admin)->post(route('admin.operations.onedrive.connect'))
        ->assertRedirect(route('admin.operations.onedrive'));
}

function pollDeviceLogin(mixed $test): TestResponse
{
    return $test->postJson(route('admin.operations.onedrive.poll'));
}

it('shows only a connect button, and after it a code to enter at Microsoft', function (): void {
    $admin = oneDriveUser();
    $this->actingAs($admin)->get(route('admin.operations.onedrive'))->assertOk()
        ->assertSee('Koppel OneDrive')
        ->assertDontSee('data-onedrive-device', false)
        ->assertDontSee('Clientgeheim')
        ->assertDontSee('/callback');

    startDeviceLogin($admin, $this);

    $page = $this->get(route('admin.operations.onedrive'))->assertOk()
        ->assertSee('ABCD-EFGH')
        ->assertSee('href="https://www.microsoft.com/link"', false)
        ->assertSee('data-poll-url="/admin/operations/onedrive/connect/poll"', false);
    expect($page->getContent())->not->toContain('secret-device-code')
        ->and(json_encode(session()->all()))->not->toContain('secret-device-code');
    Http::assertSent(fn (Request $request): bool => $request->url() === ONEDRIVE_LOGIN.'/devicecode'
        && $request['client_id'] === ONEDRIVE_APP && $request['scope'] === 'Files.Read offline_access');
});

it('waits for the interval Microsoft asked for and slows down when told to', function (): void {
    $admin = oneDriveUser();
    startDeviceLogin($admin, $this);
    Http::fake([ONEDRIVE_LOGIN.'/token' => Http::sequence()
        ->push(['error' => 'authorization_pending'], 400)
        ->push(['error' => 'slow_down'], 400)]);

    // Too early: Microsoft is not asked.
    pollDeviceLogin($this)->assertOk()->assertJson(['status' => 'pending']);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/token'));

    $this->travel(6)->seconds();
    pollDeviceLogin($this)->assertOk()->assertJson(['status' => 'pending', 'retry_after' => 5]);
    $this->travel(6)->seconds();
    pollDeviceLogin($this)->assertOk()->assertJson(['status' => 'pending', 'retry_after' => 10]);

    Http::assertSent(fn (Request $request): bool => $request->url() === ONEDRIVE_LOGIN.'/token'
        && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:device_code'
        && $request['device_code'] === 'secret-device-code' && $request['client_id'] === ONEDRIVE_APP && ! isset($request['client_secret']));
    expect(OneDriveConnection::current()->isConnected())->toBeFalse();
});

it('stores the connection once the administrator signed in and goes on to choosing a folder', function (): void {
    $admin = oneDriveUser();
    startDeviceLogin($admin, $this);
    Http::fake([
        ONEDRIVE_LOGIN.'/token' => Http::response(['access_token' => 'first-access', 'refresh_token' => 'first-refresh', 'expires_in' => 3600]),
        ONEDRIVE_GRAPH.'/me/drive*' => Http::response(['id' => 'drive-1', 'owner' => ['user' => ['displayName' => 'HV Zwartsluis']]]),
    ]);
    $this->travel(6)->seconds();

    pollDeviceLogin($this)->assertOk()->assertJson(['status' => 'connected', 'redirect' => '/admin/operations/onedrive?browse=root']);

    $connection = OneDriveConnection::current();
    expect($connection->isConnected())->toBeTrue()
        ->and($connection->account_name)->toBe('HV Zwartsluis')
        ->and($connection->connected_by_user_id)->toBe($admin->id)
        ->and(session()->has('onedrive.device'))->toBeFalse()
        ->and(DB::table('onedrive_connection_audits')->pluck('action')->all())->toContain('connected');
    $this->get(route('admin.operations.onedrive'))->assertOk()->assertSee('HV Zwartsluis')->assertDontSee('ABCD-EFGH');
});

it('ends the sign-in with a message when it is declined or the code expired', function (string $error, string $message): void {
    $admin = oneDriveUser();
    startDeviceLogin($admin, $this);
    Http::fake([ONEDRIVE_LOGIN.'/token' => Http::response(['error' => $error, 'error_description' => 'secret-device-code'], 400)]);
    $this->travel(6)->seconds();

    $answer = pollDeviceLogin($this)->assertOk()->assertJson(['status' => 'error']);

    expect($answer->json('message'))->toContain($message)->not->toContain('secret-device-code')
        ->and(session()->has('onedrive.device'))->toBeFalse()
        ->and(OneDriveConnection::current()->isConnected())->toBeFalse();
})->with([
    'declined' => ['authorization_declined', 'geweigerd'],
    'expired' => ['expired_token', 'verlopen'],
]);

it('works without script: the button asks once and returns to the page', function (): void {
    $admin = oneDriveUser();
    startDeviceLogin($admin, $this);
    Http::fake([ONEDRIVE_LOGIN.'/token' => Http::response(['error' => 'authorization_pending'], 400)]);
    $this->travel(6)->seconds();

    $this->post(route('admin.operations.onedrive.poll'))->assertRedirect('/admin/operations/onedrive')->assertSessionHas('status');
    $this->post(route('admin.operations.onedrive.cancel'))->assertRedirect(route('admin.operations.onedrive'));
    expect(session()->has('onedrive.device'))->toBeFalse();
    pollDeviceLogin($this)->assertJson(['status' => 'expired']);
});

it('shows only a Microsoft page to enter the code', function (): void {
    $admin = oneDriveUser();
    startDeviceLogin($admin, $this, ['verification_uri' => 'https://phish.example/link']);

    $this->get(route('admin.operations.onedrive'))->assertSee('href="https://www.microsoft.com/link"', false)->assertDontSee('phish.example');
});

it('uses an own client ID from the browser and returns to the shared app when it is emptied', function (): void {
    $admin = oneDriveUser();
    connectedDrive();
    $own = '11111111-2222-3333-4444-555555555555';

    $this->actingAs($admin)->post(route('admin.operations.onedrive.app'), ['client_id' => strtoupper($own)])
        ->assertRedirect(route('admin.operations.onedrive'));
    $connection = OneDriveConnection::current();
    // Tokens belong to the old app: the connection is gone.
    expect($connection->clientId())->toBe($own)
        ->and($connection->usesCustomApp())->toBeTrue()
        ->and($connection->isConnected())->toBeFalse();

    Http::fake([ONEDRIVE_LOGIN.'/devicecode' => Http::response(['device_code' => 'd', 'user_code' => 'U', 'verification_uri' => 'https://www.microsoft.com/link', 'expires_in' => 900, 'interval' => 5])]);
    $this->post(route('admin.operations.onedrive.connect'));
    Http::assertSent(fn (Request $request): bool => $request['client_id'] === $own);

    $this->post(route('admin.operations.onedrive.app'), ['client_id' => ''])->assertRedirect(route('admin.operations.onedrive'));
    expect(OneDriveConnection::current()->clientId())->toBe(ONEDRIVE_APP)
        ->and(session()->has('onedrive.device'))->toBeFalse()
        ->and(DB::table('onedrive_connection_audits')->pluck('action')->all())->toContain('custom_app_saved', 'custom_app_reset');
    $this->post(route('admin.operations.onedrive.app'), ['client_id' => 'geen-id'])->assertSessionHasErrors('client_id');
});

it('says OneDrive is not set up when there is neither a shared nor an own client ID', function (): void {
    config(['ingest.onedrive.client_id' => '']);
    $admin = oneDriveUser();

    $page = $this->actingAs($admin)->get(route('admin.operations.onedrive'))->assertOk()
        ->assertSee('data-onedrive-unavailable', false)
        ->assertDontSee('data-onedrive-connect', false);
    expect($page->getContent())->toMatch('/<details[^>]*data-onedrive-advanced[^>]*\sopen[\s>]/');
    $this->post(route('admin.operations.onedrive.connect'))->assertSessionHasErrors('onedrive');
});

it('resets a connection made with an own client secret, and keeps the list of imported files', function (): void {
    DB::table('onedrive_connections')->insert(['id' => 1, 'client_id' => '11111111-2222-3333-4444-555555555555', 'client_secret' => 'x', 'refresh_token' => 'y', 'folder_item_id' => 'F1', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('onedrive_imported_items')->insert(['item_id' => 'A', 'outcome' => 'imported', 'original_path' => "/Foto's/a.jpg", 'created_at' => now()]);
    $migration = require database_path('migrations/2026_11_19_000000_onedrive_public_client.php');

    $migration->up();
    $migration->up();

    $row = DB::table('onedrive_connections')->first();
    expect($row->client_id)->toBeNull()
        ->and($row->client_secret)->toBeNull()
        ->and($row->refresh_token)->toBeNull()
        ->and($row->folder_item_id)->toBeNull()
        ->and(DB::table('onedrive_imported_items')->count())->toBe(1)
        ->and(DB::table('onedrive_connection_audits')->where('action', 'reset_for_public_client')->count())->toBe(1);
});

it('is for administrators only', function (): void {
    $editor = oneDriveUser('editor');

    $this->actingAs($editor)->get(route('admin.operations.onedrive'))->assertForbidden();
    $this->actingAs($editor)->post(route('admin.operations.onedrive.start'), ['mode' => 'import'])->assertForbidden();
    $this->actingAs($editor)->post(route('admin.operations.onedrive.connect'))->assertForbidden();
    $this->actingAs($editor)->postJson(route('admin.operations.onedrive.poll'))->assertForbidden();
    $this->actingAs($editor)->post(route('admin.operations.onedrive.app'), ['client_id' => ''])->assertForbidden();
});

it('refuses a second OneDrive task while one is still running', function (): void {
    connectedDrive();
    $admin = oneDriveUser();
    OperationRun::create(['id' => (string) str()->ulid(), 'operation_type' => OneDriveImportJob::TYPE, 'status' => OperationRun::STATUS_RUNNING, 'requested_by_user_id' => $admin->id, 'payload' => ['mode' => 'import']]);

    $this->actingAs($admin)->post(route('admin.operations.onedrive.start'), ['mode' => 'import'])
        ->assertRedirect(route('admin.operations.onedrive'))
        ->assertSessionHasErrors('onedrive');
    expect(OperationRun::query()->where('operation_type', OneDriveImportJob::TYPE)->count())->toBe(1);
});
