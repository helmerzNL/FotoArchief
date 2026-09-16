<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Jobs\ProcessAssetOcrJob;
use App\Modules\ArchiveOperations\Models\AssetOcrText;
use App\Modules\ArchiveOperations\Services\TesseractOcrService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->artisan('migrate');

    $this->manageUsersPermission = Permission::query()->firstOrCreate(['key' => 'users.manage'], ['name' => 'Users Manage']);
    $this->manageCataloguePermission = Permission::query()->firstOrCreate(['key' => 'catalogue.manage'], ['name' => 'Catalogue Manage']);
    $this->viewAssetsPermission = Permission::query()->firstOrCreate(['key' => 'assets.view'], ['name' => 'Assets View']);
    // Starting OCR writes machine text onto the dossier, so it needs assets.update.
    $this->updateAssetsPermission = Permission::query()->firstOrCreate(['key' => 'assets.update'], ['name' => 'Assets Update']);

    $adminRole = Role::query()->firstOrCreate(['key' => 'administrator'], ['name' => 'Administrator']);
    $adminRole->permissions()->syncWithoutDetaching([$this->manageUsersPermission->id, $this->manageCataloguePermission->id, $this->viewAssetsPermission->id, $this->updateAssetsPermission->id]);

    $archivistRole = Role::query()->firstOrCreate(['key' => 'archivist'], ['name' => 'Archivist']);
    $archivistRole->permissions()->syncWithoutDetaching([$this->manageCataloguePermission->id, $this->viewAssetsPermission->id, $this->updateAssetsPermission->id]);

    $viewerRole = Role::query()->firstOrCreate(['key' => 'viewer'], ['name' => 'Viewer']);
    $viewerRole->permissions()->syncWithoutDetaching([$this->viewAssetsPermission->id]);

    $this->admin = User::query()->create([
        'name' => 'Admin User',
        'email' => 'admin@example.org',
        'password' => Hash::make('secret12345'),
    ]);
    $this->admin->roles()->attach($adminRole);

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

    Storage::fake('local');
});

test('diagnostics correctly reports disabled state when OCR is not enabled', function (): void {
    config(['services.tesseract.enabled' => false]);

    $service = app(TesseractOcrService::class);
    $diagnostics = $service->getDiagnostics();

    expect($diagnostics['enabled'])->toBeFalse();
    expect($diagnostics['available'])->toBeFalse();
    expect($diagnostics['status_message'])->toContain('OCR_ENABLED=false');
});

test('processing an asset file when OCR is disabled marks status as disabled without faking text', function (): void {
    config(['services.tesseract.enabled' => false]);

    $asset = Asset::create([
        'title' => 'Document Scan',
        'accession_number' => 'FA-OCR-001',
        'created_by_user_id' => $this->archivist->id,
    ]);

    $assetFile = AssetFile::create([
        'asset_id' => $asset->id,
        'storage_key' => 'originals/doc.jpg',
        'sha256' => hash('sha256', 'dummy'),
        'media_type' => 'image/jpeg',
        'byte_size' => 100,
        'is_primary' => true,
    ]);

    $service = app(TesseractOcrService::class);
    $ocrRecord = $service->processAssetFile($assetFile);

    expect($ocrRecord->status)->toBe('disabled');
    expect($ocrRecord->extracted_text)->toBeNull();
    expect($ocrRecord->error_message)->toContain('uitgeschakeld');
});

test('archivist can view, edit and correct machine-extracted OCR text', function (): void {
    $asset = Asset::create([
        'title' => 'Historische Briefkaart',
        'accession_number' => 'FA-OCR-002',
        'created_by_user_id' => $this->archivist->id,
    ]);

    $assetFile = AssetFile::create([
        'asset_id' => $asset->id,
        'storage_key' => 'originals/card.jpg',
        'sha256' => hash('sha256', 'dummy2'),
        'media_type' => 'image/jpeg',
        'byte_size' => 120,
        'is_primary' => true,
    ]);

    $ocr = AssetOcrText::create([
        'asset_id' => $asset->id,
        'asset_file_id' => $assetFile->id,
        'extracted_text' => 'Groete uit Amslerdam 1920',
        'status' => 'completed',
        'engine_version' => 'tesseract 5.3.0',
        'language' => 'nld',
    ]);

    // Initial state: machine text
    expect($ocr->is_edited)->toBeFalse();
    expect($ocr->getEffectiveText())->toBe('Groete uit Amslerdam 1920');

    // Archivist updates transcription
    $response = $this->actingAs($this->archivist)->post("/admin/operations/ocr/{$ocr->id}", [
        'edited_text' => 'Groeten uit Amsterdam 1920',
    ]);
    $response->assertRedirect("/admin/operations/ocr/{$ocr->id}");

    $ocr->refresh();
    expect($ocr->is_edited)->toBeTrue();
    expect($ocr->extracted_text)->toBe('Groete uit Amslerdam 1920'); // Provable machine provenance retained
    expect($ocr->edited_text)->toBe('Groeten uit Amsterdam 1920');
    expect($ocr->getEffectiveText())->toBe('Groeten uit Amsterdam 1920');
});

test('searching OCR text finds matching assets', function (): void {
    $asset1 = Asset::create([
        'title' => 'Document 1',
        'accession_number' => 'FA-OCR-003',
        'created_by_user_id' => $this->archivist->id,
    ]);
    $file1 = AssetFile::create([
        'asset_id' => $asset1->id,
        'storage_key' => 'originals/doc1.jpg',
        'sha256' => hash('sha256', 'd1'),
        'media_type' => 'image/jpeg',
        'byte_size' => 10,
        'is_primary' => true,
    ]);
    AssetOcrText::create([
        'asset_id' => $asset1->id,
        'asset_file_id' => $file1->id,
        'extracted_text' => 'Notariële akte betreffende erfenis te Utrecht',
        'status' => 'completed',
        'is_edited' => false,
    ]);

    $asset2 = Asset::create([
        'title' => 'Document 2',
        'accession_number' => 'FA-OCR-004',
        'created_by_user_id' => $this->archivist->id,
    ]);
    $file2 = AssetFile::create([
        'asset_id' => $asset2->id,
        'storage_key' => 'originals/doc2.jpg',
        'sha256' => hash('sha256', 'd2'),
        'media_type' => 'image/jpeg',
        'byte_size' => 10,
        'is_primary' => true,
    ]);
    AssetOcrText::create([
        'asset_id' => $asset2->id,
        'asset_file_id' => $file2->id,
        'extracted_text' => 'Bouwvergunning Gemeente Rotterdam',
        'status' => 'completed',
        'is_edited' => false,
    ]);

    $service = app(TesseractOcrService::class);

    $resultsUtrecht = $service->searchOcrText('Utrecht', $this->archivist);
    expect($resultsUtrecht->total())->toBe(1);
    expect($resultsUtrecht->first()?->asset_id)->toBe($asset1->id);

    $resultsRotterdam = $service->searchOcrText('Rotterdam', $this->archivist);
    expect($resultsRotterdam->total())->toBe(1);
    expect($resultsRotterdam->first()?->asset_id)->toBe($asset2->id);

    $resultsNone = $service->searchOcrText('Groningen', $this->archivist);
    expect($resultsNone->total())->toBe(0);
});

test('dispatches background OCR job on user request', function (): void {
    Queue::fake();

    $asset = Asset::create([
        'title' => 'Te Verwerken Scan',
        'accession_number' => 'FA-OCR-005',
        'created_by_user_id' => $this->archivist->id,
    ]);
    $file = AssetFile::create([
        'asset_id' => $asset->id,
        'storage_key' => 'originals/to-ocr.jpg',
        'sha256' => hash('sha256', 'to-ocr'),
        'media_type' => 'image/jpeg',
        'byte_size' => 10,
        'is_primary' => true,
    ]);

    $response = $this->actingAs($this->archivist)->post("/admin/operations/ocr/assets/{$asset->id}/dispatch");
    $response->assertRedirect();

    Queue::assertPushed(ProcessAssetOcrJob::class, function ($job) use ($file) {
        return $job->assetFileId === $file->id;
    });
});
