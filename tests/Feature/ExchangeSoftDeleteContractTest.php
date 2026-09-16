<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\DataExchange\Models\DataExport;
use App\Modules\DataExchange\Models\MetadataImport;
use App\Modules\Ingest\Jobs\ProcessUpload;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Ingest\Services\ImageProcessor;
use App\Modules\Ingest\Services\QuarantineUploadService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Exchange half of the cross-module soft-delete contract owned by Operations.
 *
 * Operations moves a photo to the trash with the standard SoftDeletes trait, so
 * the deleted_at global scope hides it from every ordinary Eloquent query.
 * Exchange relies on exactly that, which is the risk these tests cover: a
 * bundle is built once and delivered later, so a photo can be thrown away in
 * between. The media must not still walk out of the door afterwards.
 */
function trashUser(string $role = 'archivist'): User
{
    $user = User::query()->create(['name' => $role, 'email' => str()->uuid().'@example.test', 'password' => 'test-only-password']);
    $user->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

    return $user;
}

function trashPhoto(User $user, string $title = 'Dorpsstraat'): Asset
{
    $asset = Asset::query()->create([
        'accession_number' => 'FA-'.str()->ulid(),
        'created_by_user_id' => $user->id,
        'title' => $title,
    ]);
    $upload = app(QuarantineUploadService::class)->quarantine($asset, UploadedFile::fake()->image('foto.png', 400, 300), $user->id);
    (new ProcessUpload($upload->id))->handle(app(ImageProcessor::class));

    return $asset->refresh();
}

/**
 * Mirrors what Operations' TrashService records, without depending on that
 * service: the contract Exchange honours is the schema, not the caller.
 */
function trashIt(Asset $asset, User $actor): void
{
    $asset->forceFill(['deleted_by_user_id' => $actor->id, 'deletion_reason' => 'Dubbel opgenomen'])->save();
    $asset->delete();
}

function trashRun(): void
{
    for ($pass = 0; $pass < 10 && DB::table('jobs')->where('queue', 'ingest')->where('available_at', '<=', now()->getTimestamp())->exists(); $pass++) {
        Artisan::call('queue:work', ['connection' => 'ingest', '--once' => true, '--tries' => 3]);
    }
}

function trashExport(User $user, string $type, array $assetIds): DataExport
{
    test()->actingAs($user)->from('/exchange')->post('/exchange/exports', ['export_type' => $type, 'scope' => 'selection', 'asset_ids' => $assetIds]);

    return DataExport::query()->latest('id')->firstOrFail();
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    config(['filesystems.default' => 'local', 'ingest.scanner' => 'none']);
    Storage::fake('local');
});

it('runs against the real trash schema owned by Operations', function (): void {
    // If this fails, every other test in this file is proving nothing.
    expect(Schema::hasColumn('assets', 'deleted_at'))->toBeTrue()
        ->and(Schema::hasColumn('assets', 'deleted_by_user_id'))->toBeTrue()
        ->and(Schema::hasColumn('assets', 'deletion_reason'))->toBeTrue();

    $user = trashUser();
    $asset = trashPhoto($user);
    trashIt($asset, $user);

    expect(Asset::query()->find($asset->id))->toBeNull()
        ->and(Asset::withTrashed()->find($asset->id)->deletion_reason)->toBe('Dubbel opgenomen');
});

it('never accepts a photo from the trash into a new export', function (): void {
    $user = trashUser();
    $kept = trashPhoto($user, 'Blijft');
    $gone = trashPhoto($user, 'Weggegooid');
    trashIt($gone, $user);

    $this->actingAs($user)->from('/exchange')
        ->post('/exchange/exports', ['export_type' => 'package_zip', 'scope' => 'selection', 'asset_ids' => [$gone->id]])
        ->assertSessionHasErrors('asset_ids');
    expect(DataExport::query()->count())->toBe(0);

    // The whole-archive scope must not quietly sweep it up either.
    $export = trashExport($user, 'metadata_json', [$kept->id, $gone->id]);
    expect($export->assetIds())->toBe([$kept->id]);
});

it('drops a photo that reaches the trash while the export is still queued', function (): void {
    $user = trashUser();
    $kept = trashPhoto($user, 'Blijft');
    $gone = trashPhoto($user, 'Verdwijnt');
    $export = trashExport($user, 'package_zip', [$kept->id, $gone->id]);
    expect($export->assetIds())->toHaveCount(2);

    // Thrown away after the job was queued but before the worker ran it.
    trashIt($gone, $user);
    trashRun();
    $export->refresh();

    expect($export->status)->toBe('ready')
        ->and($export->asset_count)->toBe(1)
        ->and($export->assetIds())->toBe([$kept->id]);
    $skipped = $export->manifest['skipped'] ?? [];
    expect($skipped)->toHaveCount(1)
        ->and($skipped[0]['accession_number'])->toBe((string) $gone->accession_number)
        ->and($skipped[0]['reason'])->toContain('prullenbak');

    // And the bundle really does not contain the discarded photo.
    $path = Storage::disk((string) $export->storage_disk)->path((string) $export->storage_key);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }
    $zip->close();
    expect(implode("\n", $names))->not->toContain((string) $gone->accession_number)
        ->and(implode("\n", $names))->toContain((string) $kept->accession_number);
});

it('fails the whole export when every photo in it has been thrown away', function (): void {
    $user = trashUser();
    $asset = trashPhoto($user);
    $export = trashExport($user, 'package_zip', [$asset->id]);
    trashIt($asset, $user);

    trashRun();

    expect($export->fresh()->status)->toBe('failed');
    $this->actingAs($user)->get('/exchange/exports/'.$export->id)->assertOk()->assertSee('Export mislukt');
});

it('revokes a finished bundle and deletes the file when a photo is thrown away afterwards', function (): void {
    $user = trashUser();
    $asset = trashPhoto($user);
    $export = trashExport($user, 'package_zip', [$asset->id]);
    trashRun();
    $export->refresh();
    $disk = (string) $export->storage_disk;
    $key = (string) $export->storage_key;
    expect($export->status)->toBe('ready')
        ->and(Storage::disk($disk)->exists($key))->toBeTrue();

    // The bundle already holds the original. Deleting the photo must take the
    // bundle with it, or the media keeps leaking through an old request.
    trashIt($asset, $user);

    $this->actingAs($user)->post('/exchange/exports/'.$export->id.'/link')->assertForbidden();
    $export->refresh();
    expect($export->status)->toBe('revoked')
        ->and($export->storage_key)->toBeNull()
        ->and(Storage::disk($disk)->exists($key))->toBeFalse();
});

it('refuses a download link that was issued before the photo was thrown away', function (): void {
    $user = trashUser();
    $asset = trashPhoto($user);
    $export = trashExport($user, 'package_zip', [$asset->id]);
    trashRun();
    $export->refresh();
    $disk = (string) $export->storage_disk;
    $key = (string) $export->storage_key;

    // Link in hand first, photo thrown away second: the re-check at delivery is
    // the only thing left that can stop this download.
    $link = $this->actingAs($user)->post('/exchange/exports/'.$export->id.'/link');
    $location = (string) $link->headers->get('Location');
    expect($location)->not->toBe('');

    trashIt($asset, $user);

    $this->actingAs($user)->get($location)->assertForbidden();
    expect($export->fresh()->status)->toBe('revoked')
        ->and(Storage::disk($disk)->exists($key))->toBeFalse();
});

it('lets the archive export again once the photo is restored from the trash', function (): void {
    $user = trashUser();
    $asset = trashPhoto($user);
    trashIt($asset, $user);
    Asset::withTrashed()->findOrFail($asset->id)->restore();

    $export = trashExport($user, 'package_zip', [$asset->id]);
    trashRun();

    expect($export->fresh()->status)->toBe('ready');
    $link = $this->actingAs($user)->post('/exchange/exports/'.$export->id.'/link');
    $this->actingAs($user)->get((string) $link->headers->get('Location'))->assertOk();
});

it('never writes metadata onto a photo that is in the trash', function (): void {
    $user = trashUser();
    $kept = trashPhoto($user, 'Blijft');
    $gone = trashPhoto($user, 'Weggegooid');

    $csv = "accession_number,lock_version,title\n";
    $csv .= $kept->accession_number.','.$kept->lock_version.",Bijgewerkt\n";
    $csv .= $gone->accession_number.','.$gone->lock_version.",Mag niet\n";
    $this->actingAs($user)->post('/exchange/imports', [
        'write_mode' => 'overwrite',
        'file' => UploadedFile::fake()->createWithContent('metadata.csv', $csv),
    ]);
    $import = MetadataImport::query()->latest('id')->firstOrFail();

    // Thrown away between the preview and the confirmation.
    trashIt($gone, $user);

    $this->actingAs($user)->post('/exchange/imports/'.$import->id.'/confirm', ['checksum' => $import->content_sha256]);
    trashRun();

    expect($kept->fresh()->title)->toBe('Bijgewerkt')
        ->and(Asset::withTrashed()->findOrFail($gone->id)->title)->toBe('Weggegooid');
    // The row fails, and says why in terms the archivist can act on: the photo
    // is recoverable, so "bestaat niet meer" would send them hunting for a
    // problem that is not there.
    $row = $import->rows()->where('row_number', 2)->firstOrFail();
    expect($row->status)->toBe('failed')
        ->and(implode(' ', (array) $row->messages))->toContain('prullenbak')
        ->and(implode(' ', (array) $row->messages))->not->toContain('bestaat niet meer');
});

it('says a photo is in the trash rather than missing when previewing a CSV', function (): void {
    $user = trashUser();
    $gone = trashPhoto($user, 'Weggegooid');
    $accession = (string) $gone->accession_number;
    trashIt($gone, $user);

    $this->actingAs($user)->post('/exchange/imports', [
        'write_mode' => 'overwrite',
        'file' => UploadedFile::fake()->createWithContent('metadata.csv', "accession_number,lock_version,title\n".$accession.",1,Mag niet\n"),
    ]);
    $import = MetadataImport::query()->latest('id')->firstOrFail();

    $row = $import->rows()->where('row_number', 1)->firstOrFail();
    expect($row->status)->toBe('error')
        ->and(implode(' ', (array) $row->messages))->toContain('prullenbak');
    $this->actingAs($user)->get('/exchange/imports/'.$import->id)->assertOk()->assertSee('prullenbak', false);
});

it('keeps the exchange audit trail of a photo that is later thrown away', function (): void {
    $user = trashUser();
    $asset = trashPhoto($user);
    $export = trashExport($user, 'metadata_json', [$asset->id]);
    trashRun();
    $link = $this->actingAs($user)->post('/exchange/exports/'.$export->id.'/link');
    $this->actingAs($user)->get((string) $link->headers->get('Location'))->assertOk();

    trashIt($asset, $user);

    // Who exported what stays answerable after the photo is gone; the trash is
    // recoverable for thirty days and the audit trail outlives that.
    expect(AssetAuditEvent::query()->where('asset_id', $asset->id)->where('event_type', 'export.included')->exists())->toBeTrue()
        ->and(AssetAuditEvent::query()->where('asset_id', $asset->id)->where('event_type', 'export.downloaded')->exists())->toBeTrue();
});
