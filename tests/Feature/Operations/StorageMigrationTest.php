<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Services\StorageMigrationService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->artisan('migrate');

    $this->manageUsersPermission = Permission::query()->firstOrCreate(['key' => 'users.manage'], ['name' => 'Users Manage']);
    $this->manageCataloguePermission = Permission::query()->firstOrCreate(['key' => 'catalogue.manage'], ['name' => 'Catalogue Manage']);
    $this->viewAssetsPermission = Permission::query()->firstOrCreate(['key' => 'assets.view'], ['name' => 'Assets View']);

    $adminRole = Role::query()->firstOrCreate(['key' => 'administrator'], ['name' => 'Administrator']);
    $adminRole->permissions()->syncWithoutDetaching([$this->manageUsersPermission->id, $this->manageCataloguePermission->id, $this->viewAssetsPermission->id]);

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

    Storage::fake('local');
    Storage::fake('s3');
    config(['filesystems.disks.s3' => ['driver' => 's3']]);
});

test('unauthorized users cannot access storage migration', function (): void {
    $response = $this->actingAs($this->viewer)->get('/admin/operations/storage-migration');

    $response->assertForbidden();
});

test('storage migration copies files, derivatives and verifies checksums without premature deletion', function (): void {
    $asset = Asset::create([
        'title' => 'Test Migration Asset',
        'accession_number' => 'FA-MIG-001',
        'created_by_user_id' => $this->admin->id,
    ]);

    $content = 'Sample high-res original scan data for migration testing';
    $sha256 = hash('sha256', $content);
    $storageKey = 'originals/sample.jpg';
    $previewKey = 'derivatives/previews/sample.jpg';

    Storage::disk('local')->put($storageKey, $content);
    Storage::disk('local')->put($previewKey, 'preview-data');

    $assetFile = AssetFile::create([
        'asset_id' => $asset->id,
        'storage_key' => $storageKey,
        'sha256' => $sha256,
        'media_type' => 'image/jpeg',
        'byte_size' => strlen($content),
        'original_filename' => 'sample.jpg',
        'derivatives' => [$previewKey],
        'is_primary' => true,
    ]);

    $service = app(StorageMigrationService::class);
    $migration = $service->startMigration('local', 's3', $this->admin);

    expect($migration->status)->toBe('verified');
    expect($migration->verified_files)->toBe(1);
    expect($migration->failed_files)->toBe(0);

    // Verify files exist in target disk
    expect(Storage::disk('s3')->exists($storageKey))->toBeTrue();
    expect(Storage::disk('s3')->exists($previewKey))->toBeTrue();

    // Verify source files are STILL PRESENT (never deleted prematurely)
    expect(Storage::disk('local')->exists($storageKey))->toBeTrue();
    expect(Storage::disk('local')->exists($previewKey))->toBeTrue();

    // Relocation record is verified but cutover not completed
    $relocation = $migration->relocations()->first();
    expect($relocation)->not->toBeNull();
    expect($relocation->is_verified)->toBeTrue();
    expect($relocation->cutover_completed_at)->toBeNull();

    // Perform cutover
    $response = $this->actingAs($this->admin)->post("/admin/operations/storage-migration/{$migration->id}/cutover");
    $response->assertRedirect('/admin/operations/storage-migration');

    $migration->refresh();
    $relocation->refresh();
    expect($migration->status)->toBe('cutover_completed');
    expect($relocation->cutover_completed_at)->not->toBeNull();

    // Perform cleanup post-cutover
    $response = $this->actingAs($this->admin)->post("/admin/operations/storage-migration/{$migration->id}/cleanup");
    $response->assertRedirect('/admin/operations/storage-migration');

    // Now local files should be removed
    expect(Storage::disk('local')->exists($storageKey))->toBeFalse();
    expect(Storage::disk('local')->exists($previewKey))->toBeFalse();

    // S3 files remain untouched and intact
    expect(Storage::disk('s3')->exists($storageKey))->toBeTrue();
    expect(Storage::disk('s3')->get($storageKey))->toBe($content);
});
