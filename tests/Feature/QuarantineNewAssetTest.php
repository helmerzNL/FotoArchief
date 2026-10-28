<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Ingest\Models\QuarantineUpload;
use App\Modules\Ingest\Services\QuarantineUploadService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['filesystems.default' => 'local']);
    Storage::fake('local');
    $this->user = User::query()->create(['name' => 'Quarantine', 'email' => 'quarantine@example.test', 'password' => 'test-only-password']);
    $this->folder = sys_get_temp_dir().'/fotoarchief-quarantine-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->folder);
    $image = imagecreatetruecolor(40, 30);
    imagejpeg($image, $this->folder.'/foto.jpg');
    File::put($this->folder.'/notitie.jpg', 'dit is geen afbeelding');
});

afterEach(function (): void {
    File::deleteDirectory($this->folder);
});

it('writes the file and records asset, upload and path together', function (): void {
    $record = app(QuarantineUploadService::class)->quarantineNewAsset(
        ['accession_number' => 'FA-NEW-01', 'title' => 'Foto', 'created_by_user_id' => $this->user->id],
        new UploadedFile($this->folder.'/foto.jpg', 'foto.jpg', test: true),
        $this->user->id,
        'Straten/foto.jpg',
    );

    expect(Asset::query()->where('accession_number', 'FA-NEW-01')->count())->toBe(1)
        ->and($record->asset_id)->toBe(Asset::query()->where('accession_number', 'FA-NEW-01')->sole()->id)
        ->and($record->original_path)->toBe('Straten/foto.jpg')
        ->and($record->status)->toBe('queued')
        ->and(Storage::disk('local')->exists($record->storage_key))->toBeTrue();
});

it('creates no asset when the file is rejected', function (): void {
    expect(fn () => app(QuarantineUploadService::class)->quarantineNewAsset(
        ['accession_number' => 'FA-NEW-02', 'title' => 'Notitie', 'created_by_user_id' => $this->user->id],
        new UploadedFile($this->folder.'/notitie.jpg', 'notitie.jpg', test: true),
        $this->user->id,
    ))->toThrow(ValidationException::class);

    expect(Asset::query()->count())->toBe(0)->and(QuarantineUpload::query()->count())->toBe(0);
});

it('removes the stored file and creates no asset when recording fails', function (): void {
    expect(fn () => app(QuarantineUploadService::class)->quarantineNewAsset(
        ['title' => 'Zonder nummer', 'created_by_user_id' => $this->user->id],
        new UploadedFile($this->folder.'/foto.jpg', 'foto.jpg', test: true),
        $this->user->id,
    ))->toThrow(QueryException::class);

    expect(Asset::query()->count())->toBe(0)
        ->and(QuarantineUpload::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});
