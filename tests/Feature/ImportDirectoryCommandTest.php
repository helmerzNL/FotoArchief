<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Ingest\Models\QuarantineUpload;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function importFolder(string $root, string $relative, int $width = 40, int $height = 30): void
{
    $path = $root.'/'.$relative;
    File::ensureDirectoryExists(dirname($path));
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, $height, random_int(0, 0xFFFFFF));
    str_ends_with($relative, '.bmp') ? imagebmp($image, $path) : imagejpeg($image, $path);
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    config(['filesystems.default' => 'local', 'ingest.scanner' => 'none']);
    Storage::fake('local');
    $this->user = User::query()->create(['name' => 'Archivist', 'email' => 'archivist@example.test', 'password' => 'test-only-password']);
    $this->user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
    $this->folder = sys_get_temp_dir().'/fotoarchief-import-'.bin2hex(random_bytes(6));
    importFolder($this->folder, 'Straten/Havenstraat/haven-1.jpg');
    importFolder($this->folder, 'Gebeurtenissen/Koninginnedag 1950/optocht.jpg');
    importFolder($this->folder, 'Straten/scan.bmp');
    File::put($this->folder.'/Straten/kaart.tif', 'II*');
    File::put($this->folder.'/Straten/Thumbs.db', 'x');
});

afterEach(function (): void {
    File::deleteDirectory($this->folder);
});

it('imports a folder into quarantine and keeps each relative path as provenance', function (): void {
    $this->artisan('ingest:import-directory', ['path' => $this->folder, '--user' => 'archivist@example.test'])
        ->expectsOutputToContain('Nieuw aangeboden: 3')
        ->expectsOutputToContain('Nog niet ondersteund en overgeslagen: 1 × .tif')
        ->assertExitCode(0);

    expect(Asset::query()->count())->toBe(3)
        ->and(QuarantineUpload::query()->orderBy('original_path')->pluck('original_path')->all())
        ->toBe(['Gebeurtenissen/Koninginnedag 1950/optocht.jpg', 'Straten/Havenstraat/haven-1.jpg', 'Straten/scan.bmp'])
        ->and(QuarantineUpload::query()->where('original_filename', 'haven-1.jpg')->sole()->status)->toBe('queued')
        ->and(Asset::query()->where('title', 'haven-1')->sole()->created_by_user_id)->toBe($this->user->id);
});

it('is resumable: a second run skips paths that are already imported', function (): void {
    $arguments = ['path' => $this->folder, '--user' => 'archivist@example.test'];
    Artisan::call('ingest:import-directory', $arguments);
    importFolder($this->folder, 'Straten/Havenstraat/haven-2.jpg');

    $this->artisan('ingest:import-directory', $arguments)
        ->expectsOutputToContain('Nieuw aangeboden: 1')
        ->expectsOutputToContain('Al eerder geïmporteerd (zelfde pad): 3')
        ->assertExitCode(0);

    expect(Asset::query()->count())->toBe(4);
});

it('stops at the limit and reports that more remain', function (): void {
    $this->artisan('ingest:import-directory', ['path' => $this->folder, '--user' => 'archivist@example.test', '--limit' => 1])
        ->expectsOutputToContain('De limiet is bereikt')
        ->assertExitCode(0);

    expect(Asset::query()->count())->toBe(1);
});

it('stores nothing in dry-run mode and needs no account', function (): void {
    $this->artisan('ingest:import-directory', ['path' => $this->folder, '--dry-run' => true])
        ->expectsOutputToContain('Proefdraai: er is niets opgeslagen.')
        ->expectsOutputToContain('Nieuw te importeren: 3')
        ->assertExitCode(0);

    expect(Asset::query()->count())->toBe(0)->and(QuarantineUpload::query()->count())->toBe(0);
});

it('refuses a missing folder and an account that may not create photos', function (): void {
    $this->artisan('ingest:import-directory', ['path' => $this->folder.'/missing', '--user' => 'archivist@example.test'])->assertExitCode(1);
    $viewer = User::query()->create(['name' => 'Viewer', 'email' => 'viewer@example.test', 'password' => 'test-only-password']);
    $viewer->roles()->attach(Role::query()->where('key', 'viewer')->firstOrFail());

    $this->artisan('ingest:import-directory', ['path' => $this->folder, '--user' => 'viewer@example.test'])->assertExitCode(1);
    $this->artisan('ingest:import-directory', ['path' => $this->folder])->assertExitCode(1);

    expect(Asset::query()->count())->toBe(0);
});

it('does not follow symlinks out of the import folder', function (): void {
    $outside = sys_get_temp_dir().'/fotoarchief-outside-'.bin2hex(random_bytes(6));
    importFolder($outside, 'secret.jpg');
    symlink($outside, $this->folder.'/link');

    try {
        $this->artisan('ingest:import-directory', ['path' => $this->folder, '--dry-run' => true])
            ->expectsOutputToContain('Nieuw te importeren: 3')
            ->assertExitCode(0);
    } finally {
        File::deleteDirectory($outside);
    }
});

it('carries the path through processing onto the stored file and the photo page', function (): void {
    Artisan::call('ingest:import-directory', ['path' => $this->folder, '--user' => 'archivist@example.test']);
    Artisan::call('outbox:dispatch');
    Artisan::call('queue:work', ['connection' => 'ingest', '--stop-when-empty' => true, '--tries' => 3]);

    $file = AssetFile::query()->where('original_filename', 'haven-1.jpg')->sole();
    expect($file->original_path)->toBe('Straten/Havenstraat/haven-1.jpg');
    $this->actingAs($this->user)->get('/admin/assets/'.$file->asset_id)
        ->assertOk()->assertSee('Oorspronkelijk pad')->assertSee('Straten/Havenstraat/haven-1.jpg');
});

it('converts a BMP original to JPEG previews while keeping the original untouched', function (): void {
    Artisan::call('ingest:import-directory', ['path' => $this->folder, '--user' => 'archivist@example.test']);
    Artisan::call('outbox:dispatch');
    Artisan::call('queue:work', ['connection' => 'ingest', '--stop-when-empty' => true, '--tries' => 3]);

    $file = AssetFile::query()->where('original_filename', 'scan.bmp')->sole();
    expect($file->media_type)->toBe('image/bmp')
        ->and($file->sha256)->toBe(hash_file('sha256', $this->folder.'/Straten/scan.bmp'))
        ->and(getimagesizefromstring(Storage::disk('local')->get($file->derivatives['preview300']))['mime'])->toBe('image/jpeg');
})->skip(! function_exists('imagecreatefrombmp'), 'GD without BMP support');

it('keeps the folder path of a duplicate and shows it on the surviving photo', function (): void {
    copy($this->folder.'/Straten/Havenstraat/haven-1.jpg', $this->folder.'/Gebeurtenissen/Koninginnedag 1950/haven-dubbel.jpg');
    Artisan::call('ingest:import-directory', ['path' => $this->folder, '--user' => 'archivist@example.test']);
    Artisan::call('outbox:dispatch', ['--limit' => 100]);
    Artisan::call('queue:work', ['connection' => 'ingest', '--stop-when-empty' => true, '--tries' => 3]);

    $kept = QuarantineUpload::query()->whereNotNull('duplicate_of_asset_id')->sole();
    expect($kept->status)->toBe('rejected');
    $this->actingAs($this->user)->get('/admin/assets/'.$kept->duplicate_of_asset_id)
        ->assertOk()->assertSee('Dezelfde foto is ook aangetroffen op')->assertSee($kept->original_path);
});

it('offers PDF documents for import next to photos', function (): void {
    File::put($this->folder.'/Straten/brief.pdf', '%PDF-1.4');

    $this->artisan('ingest:import-directory', ['path' => $this->folder, '--dry-run' => true])
        ->expectsOutputToContain('Nieuw te importeren: 4')
        ->expectsOutputToContain('Nog niet ondersteund en overgeslagen: 1 × .tif')
        ->assertExitCode(0);
});
