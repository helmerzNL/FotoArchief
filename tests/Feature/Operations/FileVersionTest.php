<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Services\FileVersionService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\AssetVersion;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Ingest\Models\QuarantineUpload;
use App\Modules\Ingest\Services\ImageProcessor;
use App\Modules\Ingest\Services\MalwareScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->artisan('migrate');
    Storage::fake('local');

    $this->createAssetsPermission = Permission::query()->firstOrCreate(['key' => 'assets.create'], ['name' => 'Assets Create']);
    $this->updateAssetsPermission = Permission::query()->firstOrCreate(['key' => 'assets.update'], ['name' => 'Assets Update']);
    $this->viewAssetsPermission = Permission::query()->firstOrCreate(['key' => 'assets.view'], ['name' => 'Assets View']);

    $archivistRole = Role::query()->firstOrCreate(['key' => 'archivist'], ['name' => 'Archivist']);
    $archivistRole->permissions()->syncWithoutDetaching([
        $this->createAssetsPermission->id,
        $this->updateAssetsPermission->id,
        $this->viewAssetsPermission->id,
    ]);

    $viewerRole = Role::query()->firstOrCreate(['key' => 'viewer'], ['name' => 'Viewer']);
    $viewerRole->permissions()->syncWithoutDetaching([$this->viewAssetsPermission->id]);

    $this->archivist = User::query()->create([
        'name' => 'Archivist User',
        'email' => 'archivist@example.org',
        'password' => Hash::make('secret12345'),
    ]);
    $this->archivist->roles()->attach($archivistRole);

    $this->viewer = User::query()->create([
        'name' => 'Viewer User',
        'email' => 'viewer@example.org',
        'password' => Hash::make('secret12345'),
    ]);
    $this->viewer->roles()->attach($viewerRole);

    $this->asset = Asset::query()->create([
        'accession_number' => 'FA-2001',
        'title' => 'Stadhuis Markt',
        'created_by_user_id' => $this->archivist->id,
    ]);

    // Sample 1x1 image v1
    $v1Image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $this->v1Sha = hash('sha256', $v1Image);
    Storage::disk('local')->put('originals/v1-photo.png', $v1Image);

    $this->v1File = AssetFile::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/v1-photo.png',
        'sha256' => $this->v1Sha,
        'media_type' => 'image/png',
        'byte_size' => strlen($v1Image),
        'original_filename' => 'stadhuis_scan_v1.png',
        'pixel_width' => 1,
        'pixel_height' => 1,
        'ingest_status' => 'ready_private',
        'scanner_status' => 'clean',
        'is_primary' => true,
    ]);

    $this->v1Version = AssetVersion::query()->create([
        'asset_id' => $this->asset->id,
        'asset_file_id' => $this->v1File->id,
        'version_number' => 1,
        'change_type' => 'initial_scan',
        'change_note' => 'Eerste scan van glasnegatief.',
        'is_current' => true,
    ]);
});

it('lists asset versions for authorized users', function (): void {
    $response = $this->actingAs($this->archivist)->get("/admin/operations/assets/{$this->asset->id}/versions");
    $response->assertStatus(200);
    $response->assertSee('Bestandsversies', false);
    $response->assertSee('stadhuis_scan_v1.png');
    $response->assertSee('v1');
});

it('denies version upload to users without create or update permissions', function (): void {
    $file = UploadedFile::fake()->image('rescan.jpg', 100, 100);
    $response = $this->actingAs($this->viewer)->post("/admin/operations/assets/{$this->asset->id}/versions", [
        'file' => $file,
    ]);
    $response->assertStatus(403);
});

it('uploads improved scan version and creates immutable v2 preserving v1 original and checksum', function (): void {
    $im = imagecreatetruecolor(10, 10);
    imagefill($im, 0, 0, 16711680);
    ob_start();
    imagepng($im);
    $v2Image = (string) ob_get_clean();
    imagedestroy($im);
    $v2Sha = hash('sha256', $v2Image);
    Storage::disk('local')->put('quarantine/rescan.png', $v2Image);

    $upload = QuarantineUpload::query()->create([
        'asset_id' => $this->asset->id,
        'uploaded_by_user_id' => $this->archivist->id,
        'storage_disk' => 'local',
        'storage_key' => 'quarantine/rescan.png',
        'original_filename' => 'stadhuis_hoge_resolutie_v2.png',
        'byte_size' => strlen($v2Image),
        'status' => 'running',
    ]);

    $processor = new ImageProcessor(new MalwareScanner);
    $processor->process($upload);

    $this->v1File->refresh();
    $this->v1Version->refresh();

    // v1 remains intact and immutable
    expect($this->v1File->sha256)->toBe($this->v1Sha);
    expect($this->v1File->is_primary)->toBeFalse();
    expect($this->v1Version->is_current)->toBeFalse();
    expect($this->v1Version->version_number)->toBe(1);

    // v2 exists as active version
    $v2Version = AssetVersion::query()->where('asset_id', $this->asset->id)->where('version_number', 2)->first();
    expect($v2Version)->not->toBeNull();
    expect($v2Version->is_current)->toBeTrue();

    $v2File = AssetFile::query()->where('sha256', $v2Sha)->first();
    expect($v2File)->not->toBeNull();
    expect($v2File->is_primary)->toBeTrue();
    expect($v2File->asset_id)->toBe($this->asset->id);
    expect($this->asset->files()->count())->toBe(2);
    expect($this->asset->versions()->count())->toBe(2);
});

it('reprocesses derivatives of a version from the immutable original', function (): void {
    $service = app(FileVersionService::class);
    $service->reprocessDerivatives($this->v1File, $this->archivist);

    $this->v1File->refresh();
    expect($this->v1File->derivatives)->toBeArray();
    expect($this->v1File->derivatives)->toHaveKeys(['preview300', 'preview1200', 'preview2000']);
    expect(Storage::disk('local')->exists($this->v1File->derivatives['preview300']))->toBeTrue();

    // Check audit log
    $audit = AssetAuditEvent::query()->where('asset_id', $this->asset->id)->where('event_type', 'version.reprocessed')->first();
    expect($audit)->not->toBeNull();
});

it('allows switching active primary version', function (): void {
    // Create a second version
    $v2File = AssetFile::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/v2-photo.png',
        'sha256' => hash('sha256', 'v2-bytes'),
        'media_type' => 'image/png',
        'byte_size' => 2048,
        'original_filename' => 'v2.png',
        'pixel_width' => 200,
        'pixel_height' => 200,
        'ingest_status' => 'ready_private',
        'scanner_status' => 'clean',
        'is_primary' => false,
    ]);

    $v2Version = AssetVersion::query()->create([
        'asset_id' => $this->asset->id,
        'asset_file_id' => $v2File->id,
        'version_number' => 2,
        'change_type' => 'rescan',
        'is_current' => false,
    ]);

    $response = $this->actingAs($this->archivist)->post("/admin/operations/assets/{$this->asset->id}/versions/{$v2File->id}/set-active");
    $response->assertRedirect(route('admin.operations.versions.index', $this->asset));

    $v2File->refresh();
    $v2Version->refresh();
    $this->v1File->refresh();
    $this->v1Version->refresh();

    expect($v2File->is_primary)->toBeTrue();
    expect($v2Version->is_current)->toBeTrue();
    expect($this->v1File->is_primary)->toBeFalse();
    expect($this->v1Version->is_current)->toBeFalse();
});
