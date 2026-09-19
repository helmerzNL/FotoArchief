<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Ingest\Jobs\AssembleUpload;
use App\Modules\Ingest\Models\JobOutboxMessage;
use App\Modules\Ingest\Models\UploadSession;
use App\Modules\Ingest\Services\QuarantineUploadService;
use App\Modules\Ingest\Services\UploadSessionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function uploadSessionUser(): User
{
    $user = User::query()->create(['name' => 'Upload tester', 'email' => str()->uuid().'@example.test', 'password' => 'test-password']);
    $user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());

    return $user;
}

function sessionManifest(string $bytes, string $filename = 'photo.png'): array
{
    return ['client_key' => (string) str()->uuid(), 'files' => [['filename' => $filename, 'byte_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]]];
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    Storage::fake('local');
    config(['filesystems.default' => 'local']);
    $this->user = uploadSessionUser();
    $this->actingAs($this->user);
});

it('persists private receipts across sessions and rejects conflicting replay without duplicating work', function (): void {
    $data = sessionManifest('bytes');
    $url = $this->postJson('/admin/uploads', $data)->assertCreated()->json('url');
    $this->postJson('/admin/uploads', $data)->assertCreated()->assertJsonPath('url', $url);
    $changed = $data;
    $changed['files'][0]['filename'] = 'different.png';
    $this->postJson('/admin/uploads', $changed)->assertConflict();
    expect(UploadSession::count())->toBe(1);
    $this->get('/admin/uploads')->assertOk()->assertSee(UploadSession::query()->sole()->id);
    $this->getJson($url)->assertOk()->assertJsonPath('items.0.status', 'receiving')->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs(uploadSessionUser())->getJson($url)->assertForbidden();
});

it('receives chunks idempotently and assembles only complete verified files once on the ingest queue', function (): void {
    $image = UploadedFile::fake()->image('photo.png', 100, 100);
    $bytes = file_get_contents($image->getPathname());
    $url = $this->postJson('/admin/uploads', sessionManifest($bytes))->json('url');
    $item = UploadSession::query()->sole()->items()->sole();
    $base = $url.'/items/'.$item->id;
    $this->postJson($base.'/finalize')->assertUnprocessable();
    $this->postJson($base.'/chunk', ['position' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', $bytes)])->assertOk();
    $this->postJson($base.'/chunk', ['position' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', $bytes)])->assertOk();
    $this->postJson($base.'/chunk', ['position' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', str_repeat('x', strlen($bytes)))])->assertConflict();
    $this->getJson($url)->assertJsonPath('items.0.received', [0]);
    $this->postJson($base.'/finalize')->assertAccepted();
    $this->postJson($base.'/finalize')->assertAccepted();
    expect(DB::table('jobs')->count())->toBe(1)->and(Asset::count())->toBe(0);
    $job = new AssembleUpload($item->id);
    $job->handle(app(UploadSessionService::class), app(QuarantineUploadService::class));
    $job->handle(app(UploadSessionService::class), app(QuarantineUploadService::class));
    expect(Asset::count())->toBe(1)->and(DB::table('jobs')->count())->toBe(1)
        ->and(JobOutboxMessage::query()->where('status', 'pending')->count())->toBe(1)
        ->and($item->fresh()->status)->toBe('received');
    $upload = $item->fresh()->upload;
    expect(Storage::disk('local')->get($upload->storage_key))->toBe($bytes);
    $this->get($url)->assertOk()->assertSee('Batchsamenvatting')->assertSee('photo.png');
});

it('requires exact chunk sizes and resumes only missing chunks without early ingest', function (): void {
    $bytes = str_repeat('a', UploadSessionService::CHUNK_BYTES).'tail';
    $url = $this->postJson('/admin/uploads', sessionManifest($bytes))->json('url');
    $item = UploadSession::query()->sole()->items()->sole();
    $base = $url.'/items/'.$item->id;
    $this->postJson($base.'/chunk', ['position' => 1, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', 'tail')])->assertOk();
    $this->postJson($base.'/finalize')->assertUnprocessable();
    $this->postJson($base.'/chunk', ['position' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', 'short')])->assertUnprocessable();
    $this->getJson($url)->assertJsonPath('items.0.received', [1]);
    $this->postJson($base.'/chunk', ['position' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', substr($bytes, 0, UploadSessionService::CHUNK_BYTES))])->assertOk();
    $this->postJson($base.'/finalize')->assertAccepted();
    (new AssembleUpload($item->id))->handle(app(UploadSessionService::class), app(QuarantineUploadService::class));
    expect($item->fresh()->status)->toBe('rejected')->and(Asset::count())->toBe(0);
    $this->postJson($base.'/finalize')->assertConflict();
});

it('detects corruption before creating assets and never retries rejected content', function (): void {
    $url = $this->postJson('/admin/uploads', sessionManifest('abcd'))->json('url');
    $item = UploadSession::query()->sole()->items()->sole();
    $base = $url.'/items/'.$item->id;
    $this->postJson($base.'/chunk', ['position' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', 'different')])->assertUnprocessable();
    $this->postJson($base.'/chunk', ['position' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', 'efgh')])->assertOk();
    $this->postJson($base.'/finalize')->assertAccepted();
    (new AssembleUpload($item->id))->handle(app(UploadSessionService::class), app(QuarantineUploadService::class));
    expect($item->fresh()->error_code)->toBe('integrity')->and(Asset::count())->toBe(0);
    $this->post($base.'/retry', ['confirm' => 1])->assertConflict();
});

it('enforces manifest quotas and format selection before reserving storage', function (): void {
    $data = sessionManifest('test');
    $this->postJson('/admin/uploads', sessionManifest('test', 'bad.svg'))->assertUnprocessable();
    $data['files'][0]['byte_size'] = 104857601;
    $this->postJson('/admin/uploads', $data)->assertUnprocessable();
    $data = sessionManifest('test');
    $data['files'][] = $data['files'][0];
    $this->postJson('/admin/uploads', $data)->assertUnprocessable();
    for ($i = 0; $i < 4; $i++) {
        $this->postJson('/admin/uploads', sessionManifest('test'))->assertCreated();
    }
    $this->postJson('/admin/uploads', sessionManifest('test'))->assertUnprocessable();
});

it('retains expired receipts while safely pruning chunks and refusing late workers', function (): void {
    $url = $this->postJson('/admin/uploads', sessionManifest('test'))->json('url');
    $session = UploadSession::query()->sole();
    $item = $session->items()->sole();
    $base = $url.'/items/'.$item->id;
    $this->postJson($base.'/chunk', ['position' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', 'test')])->assertOk();
    $this->post($url.'/close', ['confirm' => 1])->assertConflict();
    $this->postJson($base.'/finalize')->assertAccepted();
    $this->travel(8)->days();
    $this->postJson($base.'/chunk', ['position' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', 'test')])->assertConflict();
    expect(app(UploadSessionService::class)->prune())->toBe(1);
    expect(Storage::disk('local')->allFiles('upload-sessions'))->toBe([]);
    (new AssembleUpload($item->id))->handle(app(UploadSessionService::class), app(QuarantineUploadService::class));
    expect($item->fresh()->status)->toBe('expired')->and(Asset::count())->toBe(0)->and(UploadSession::count())->toBe(1);
});

it('rejects cross-session items and rechecks upload permissions in background processing', function (): void {
    $url = $this->postJson('/admin/uploads', sessionManifest('test'))->json('url');
    $item = UploadSession::query()->sole()->items()->sole();
    $other = $this->postJson('/admin/uploads', sessionManifest('other'))->json('url');
    $this->postJson($other.'/items/'.$item->id.'/finalize')->assertNotFound();
    $base = $url.'/items/'.$item->id;
    $this->postJson($base.'/chunk', ['position' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', 'test')])->assertOk();
    $this->postJson($base.'/finalize')->assertAccepted();
    $this->user->roles()->detach();
    (new AssembleUpload($item->id))->handle(app(UploadSessionService::class), app(QuarantineUploadService::class));
    expect($item->fresh()->status)->toBe('expired')->and(Asset::count())->toBe(0);
});
