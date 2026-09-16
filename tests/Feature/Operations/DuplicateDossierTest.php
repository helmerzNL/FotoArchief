<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Ingest\Models\QuarantineUpload;
use App\Modules\Ingest\Services\ImageProcessor;
use App\Modules\Ingest\Services\MalwareScanner;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->artisan('migrate');
    Storage::fake('local');

    $this->updateAssetsPermission = Permission::query()->firstOrCreate(['key' => 'assets.update'], ['name' => 'Assets Update']);
    $this->viewAssetsPermission = Permission::query()->firstOrCreate(['key' => 'assets.view'], ['name' => 'Assets View']);
    $this->manageCataloguePermission = Permission::query()->firstOrCreate(['key' => 'catalogue.manage'], ['name' => 'Catalogue Manage']);

    $archivistRole = Role::query()->firstOrCreate(['key' => 'archivist'], ['name' => 'Archivist']);
    $archivistRole->permissions()->syncWithoutDetaching([
        $this->updateAssetsPermission->id,
        $this->viewAssetsPermission->id,
        $this->manageCataloguePermission->id,
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

    // Create existing asset with AssetFile
    $this->existingAsset = Asset::query()->create([
        'accession_number' => 'FA-1001',
        'title' => 'Oorspronkelijke Foto Dorpsplein',
        'description' => 'Originele opname uit 1930.',
        'created_by_user_id' => $this->archivist->id,
    ]);

    $this->dummySha = hash('sha256', 'sample-photo-content');

    $this->existingFile = AssetFile::query()->create([
        'asset_id' => $this->existingAsset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/existing-photo.jpg',
        'sha256' => $this->dummySha,
        'media_type' => 'image/jpeg',
        'byte_size' => 1024,
        'original_filename' => 'dorpsplein_1930.jpg',
        'pixel_width' => 800,
        'pixel_height' => 600,
        'ingest_status' => 'ready_private',
        'scanner_status' => 'clean',
    ]);
});

it('detects duplicate in ImageProcessor and records link to existing file without creating duplicate file', function (): void {
    // 1x1 1-byte PNG or JPEG image
    $sampleImage = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $sampleSha = hash('sha256', $sampleImage);

    // Update existing file to match this sample SHA
    $this->existingFile->delete();
    $this->existingFile = AssetFile::query()->create([
        'asset_id' => $this->existingAsset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/sample.png',
        'sha256' => $sampleSha,
        'media_type' => 'image/png',
        'byte_size' => strlen($sampleImage),
        'original_filename' => 'sample.png',
        'pixel_width' => 1,
        'pixel_height' => 1,
        'ingest_status' => 'ready_private',
        'scanner_status' => 'clean',
    ]);

    Storage::disk('local')->put('quarantine/new-duplicate.png', $sampleImage);

    $duplicateAsset = Asset::query()->create([
        'accession_number' => 'FA-DUPE-1',
        'title' => 'Tweede Scan Zelfde Foto',
        'created_by_user_id' => $this->archivist->id,
    ]);

    $upload = QuarantineUpload::query()->create([
        'asset_id' => $duplicateAsset->id,
        'uploaded_by_user_id' => $this->archivist->id,
        'storage_disk' => 'local',
        'storage_key' => 'quarantine/new-duplicate.png',
        'original_filename' => 'tweede_scan.png',
        'byte_size' => strlen($sampleImage),
        'status' => 'running',
    ]);

    $processor = new ImageProcessor(new MalwareScanner);

    expect(fn () => $processor->process($upload))->toThrow(ValidationException::class);

    $upload->refresh();
    expect($upload->duplicate_of_asset_id)->toBe($this->existingAsset->id);
    expect($upload->duplicate_of_file_id)->toBe($this->existingFile->id);
    expect($upload->detected_sha256)->toBe($sampleSha);

    // Verify no second AssetFile was created
    expect(AssetFile::query()->where('sha256', $sampleSha)->count())->toBe(1);
});

it('denies duplicate management to unauthorized users', function (): void {
    $upload = QuarantineUpload::query()->create([
        'asset_id' => $this->existingAsset->id,
        'duplicate_of_asset_id' => $this->existingAsset->id,
        'duplicate_of_file_id' => $this->existingFile->id,
        'detected_sha256' => $this->dummySha,
        'storage_disk' => 'local',
        'storage_key' => 'quarantine/dupe.jpg',
        'original_filename' => 'dupe.jpg',
        'byte_size' => 1024,
        'status' => 'rejected',
    ]);

    $response = $this->actingAs($this->viewer)->get('/admin/operations/duplicates');
    $response->assertStatus(403);

    $showResponse = $this->actingAs($this->viewer)->get("/admin/operations/duplicates/{$upload->id}");
    $showResponse->assertStatus(403);
});

it('allows authorized archivist to view side-by-side duplicate comparison and link to existing asset', function (): void {
    $upload = QuarantineUpload::query()->create([
        'asset_id' => $this->existingAsset->id,
        'uploaded_by_user_id' => $this->archivist->id,
        'duplicate_of_asset_id' => $this->existingAsset->id,
        'duplicate_of_file_id' => $this->existingFile->id,
        'detected_sha256' => $this->dummySha,
        'storage_disk' => 'local',
        'storage_key' => 'quarantine/dupe.jpg',
        'original_filename' => 'dupe_schenking_jansen.jpg',
        'byte_size' => 1024,
        'status' => 'rejected',
    ]);

    $showResponse = $this->actingAs($this->archivist)->get("/admin/operations/duplicates/{$upload->id}");
    $showResponse->assertStatus(200);
    $showResponse->assertSee('Duplicaat Dossier Vergelijken', false);
    $showResponse->assertSee('dupe_schenking_jansen.jpg');
    $showResponse->assertSee('FA-1001');

    // Perform linking and enrichment
    $linkResponse = $this->actingAs($this->archivist)->post("/admin/operations/duplicates/{$upload->id}/link", [
        'provenance_note' => 'Schenking Fam. Jansen 2026, met annotatie achterop.',
        'tags' => 'dorpsfeest, fanfare',
    ]);

    $linkResponse->assertRedirect(route('admin.assets.show', $this->existingAsset));

    $this->existingAsset->refresh();
    expect($this->existingAsset->description)->toContain('Schenking Fam. Jansen 2026');
    expect($this->existingAsset->tags->pluck('name')->all())->toContain('dorpsfeest', 'fanfare');

    $upload->refresh();
    expect($upload->status)->toBe('resolved_duplicate');

    // Audit event recorded
    $audit = AssetAuditEvent::query()->where('asset_id', $this->existingAsset->id)->where('event_type', 'duplicate.linked')->first();
    expect($audit)->not->toBeNull();
    expect($audit->actor_user_id)->toBe($this->archivist->id);

    // Verify still strictly 1 AssetFile
    expect(AssetFile::query()->where('asset_id', $this->existingAsset->id)->count())->toBe(1);
});
