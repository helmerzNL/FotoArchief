<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Models\IntegrityCheck;
use App\Modules\ArchiveOperations\Services\IntegrityVerificationService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->artisan('migrate');
    Storage::fake('local');

    $this->updateAssetsPermission = Permission::query()->firstOrCreate(['key' => 'assets.update'], ['name' => 'Assets Update']);
    $this->viewAssetsPermission = Permission::query()->firstOrCreate(['key' => 'assets.view'], ['name' => 'Assets View']);
    $this->manageUsersPermission = Permission::query()->firstOrCreate(['key' => 'users.manage'], ['name' => 'Users Manage']);

    $adminRole = Role::query()->firstOrCreate(['key' => 'administrator'], ['name' => 'Administrator']);
    $adminRole->permissions()->syncWithoutDetaching([
        $this->updateAssetsPermission->id,
        $this->viewAssetsPermission->id,
        $this->manageUsersPermission->id,
    ]);

    $viewerRole = Role::query()->firstOrCreate(['key' => 'viewer'], ['name' => 'Viewer']);
    $viewerRole->permissions()->syncWithoutDetaching([$this->viewAssetsPermission->id]);

    $this->admin = User::query()->create([
        'name' => 'Admin User',
        'email' => 'admin@example.org',
        'password' => Hash::make('secret12345'),
    ]);
    $this->admin->roles()->attach($adminRole);

    $this->viewer = User::query()->create([
        'name' => 'Viewer User',
        'email' => 'viewer@example.org',
        'password' => Hash::make('secret12345'),
    ]);
    $this->viewer->roles()->attach($viewerRole);

    $this->asset = Asset::query()->create([
        'accession_number' => 'FA-INTEG-1',
        'title' => 'Integriteit Test Item',
        'created_by_user_id' => $this->admin->id,
    ]);

    // Sample valid GD PNG image
    $im = imagecreatetruecolor(20, 20);
    imagefill($im, 0, 0, 16711680);
    ob_start();
    imagepng($im);
    $this->validBytes = (string) ob_get_clean();
    imagedestroy($im);

    $this->validSha = hash('sha256', $this->validBytes);
    Storage::disk('local')->put('originals/integ.png', $this->validBytes);
    Storage::disk('local')->put('derivatives/integ-preview-300.jpg', 'fake-jpg-300');
    Storage::disk('local')->put('derivatives/integ-preview-1200.jpg', 'fake-jpg-1200');
    Storage::disk('local')->put('derivatives/integ-preview-2000.jpg', 'fake-jpg-2000');

    $this->file = AssetFile::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/integ.png',
        'sha256' => $this->validSha,
        'media_type' => 'image/png',
        'byte_size' => strlen($this->validBytes),
        'original_filename' => 'integ.png',
        'pixel_width' => 20,
        'pixel_height' => 20,
        'derivatives' => [
            'preview300' => 'derivatives/integ-preview-300.jpg',
            'preview1200' => 'derivatives/integ-preview-1200.jpg',
            'preview2000' => 'derivatives/integ-preview-2000.jpg',
        ],
        'ingest_status' => 'ready_private',
        'scanner_status' => 'clean',
        'is_primary' => true,
    ]);
});

it('verifies a healthy archive file and reports ok status', function (): void {
    $service = app(IntegrityVerificationService::class);
    $result = $service->verifyFile($this->file);

    expect($result['status'])->toBe('ok');
    $check = IntegrityCheck::query()->where('asset_file_id', $this->file->id)->latest('id')->first();
    expect($check)->not->toBeNull();
    expect($check->status)->toBe('ok');
    expect($check->resolved_at)->not->toBeNull();
});

it('detects when an original file is missing from storage', function (): void {
    Storage::disk('local')->delete('originals/integ.png');

    $service = app(IntegrityVerificationService::class);
    $result = $service->verifyFile($this->file);

    expect($result['status'])->toBe('missing_original');
    $check = IntegrityCheck::query()->where('asset_file_id', $this->file->id)->latest('id')->first();
    expect($check->status)->toBe('missing_original');
    expect($check->resolved_at)->toBeNull();
});

it('detects when an original file is corrupted and has checksum mismatch', function (): void {
    Storage::disk('local')->put('originals/integ.png', 'corrupted-byte-data');

    $service = app(IntegrityVerificationService::class);
    $result = $service->verifyFile($this->file);

    expect($result['status'])->toBe('corrupt_checksum');
    $check = IntegrityCheck::query()->where('asset_file_id', $this->file->id)->latest('id')->first();
    expect($check->status)->toBe('corrupt_checksum');
    expect($check->expected_sha256)->toBe($this->validSha);
    expect($check->actual_sha256)->toBe(hash('sha256', 'corrupted-byte-data'));
});

it('detects missing derivative preview and rebuilds it from immutable original', function (): void {
    Storage::disk('local')->delete('derivatives/integ-preview-1200.jpg');

    $service = app(IntegrityVerificationService::class);
    $result = $service->verifyFile($this->file);

    expect($result['status'])->toBe('missing_derivative');

    // Rebuild derivatives
    $service->rebuildMissingDerivatives($this->file, $this->admin);

    $this->file->refresh();
    expect($this->file->derivatives)->toBeArray();
    expect(Storage::disk('local')->exists($this->file->derivatives['preview1200']))->toBeTrue();

    // Verify subsequent check reports ok
    $recheck = $service->verifyFile($this->file);
    expect($recheck['status'])->toBe('ok');
});

it('renders integrity dashboard and enforces authorization', function (): void {
    $response = $this->actingAs($this->viewer)->get('/admin/operations/integrity');
    $response->assertStatus(403);

    $adminResponse = $this->actingAs($this->admin)->get('/admin/operations/integrity');
    $adminResponse->assertStatus(200);
    $adminResponse->assertSee('Bestandsintegriteit', false);
    $adminResponse->assertSee('Archiefbestanden');
});
