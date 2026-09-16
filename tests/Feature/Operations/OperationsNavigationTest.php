<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Models\AssetOcrText;
use App\Modules\ArchiveOperations\Models\IntegrityCheck;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Models\StorageMigration;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\AssetVersion;
use App\Modules\Ingest\Models\QuarantineUpload;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A feature nobody can reach is a feature nobody has. These cases follow only what
 * the pages actually render: if a link is missing here, an operator would have to
 * type the URL from memory.
 */
beforeEach(function (): void {
    $this->artisan('migrate');

    $keys = ['users.manage', 'catalogue.manage', 'assets.view', 'assets.update', 'assets.create', 'audit.view'];
    $this->permissions = [];
    foreach ($keys as $key) {
        $this->permissions[$key] = Permission::query()->firstOrCreate(['key' => $key], ['name' => $key]);
    }

    $adminRole = Role::query()->firstOrCreate(['key' => 'administrator'], ['name' => 'Administrator']);
    $adminRole->permissions()->syncWithoutDetaching(collect($this->permissions)->pluck('id')->all());

    $viewerRole = Role::query()->firstOrCreate(['key' => 'viewer'], ['name' => 'Viewer']);
    $viewerRole->permissions()->syncWithoutDetaching([$this->permissions['assets.view']->id]);

    $this->admin = User::query()->create([
        'name' => 'Nav Admin',
        'email' => 'navadmin@example.org',
        'password' => Hash::make('secret12345'),
    ]);
    $this->admin->roles()->attach($adminRole);

    $this->viewer = User::query()->create([
        'name' => 'Nav Viewer',
        'email' => 'navviewer@example.org',
        'password' => Hash::make('secret12345'),
    ]);
    $this->viewer->roles()->attach($viewerRole);

    Storage::fake('local');

    $this->asset = Asset::query()->create([
        'title' => 'Navigatie Testfoto',
        'accession_number' => 'FA-NAV-001',
        'created_by_user_id' => $this->admin->id,
    ]);

    Storage::disk('local')->put('originals/nav.jpg', 'nav-bytes');
    $this->file = AssetFile::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'originals/nav.jpg',
        'sha256' => hash('sha256', 'nav-bytes'),
        'media_type' => 'image/jpeg',
        'byte_size' => 9,
        'original_filename' => 'nav.jpg',
        'derivatives' => [],
        'ingest_status' => 'ready_private',
        'is_primary' => true,
    ]);
});

it('offers every operations feature as a link from the diagnostics page an operator lands on', function (): void {
    $response = $this->actingAs($this->admin)->get('/admin/operations/diagnostics');
    $response->assertOk();

    // The eight features of this module, each reachable without typing a URL.
    $response->assertSee(route('admin.operations.duplicates.index'), false);
    $response->assertSee(route('admin.operations.processing.index'), false);
    $response->assertSee(route('admin.operations.integrity.index'), false);
    $response->assertSee(route('admin.operations.storage.index'), false);
    $response->assertSee(route('admin.operations.trash.index'), false);
    $response->assertSee(route('admin.operations.ocr.index'), false);
    $response->assertSee(route('admin.operations.runs.index'), false);
    // File versions are per photo, so the menu routes to the photo overview.
    $response->assertSee(route('admin.assets.index'), false);

    $response->assertSee('Archiefbewerkingen', false);
    $response->assertSee('aria-current="page"', false);
});

it('keeps the operations menu on every operations page so no page is a dead end', function (): void {
    QuarantineUpload::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'quarantine/nav.jpg',
        'original_filename' => 'nav.jpg',
        'byte_size' => 9,
        'status' => 'failed',
        'uploaded_by_user_id' => $this->admin->id,
    ]);

    $pages = [
        '/admin/operations/diagnostics',
        '/admin/operations/duplicates',
        '/admin/operations/processing',
        '/admin/operations/integrity',
        '/admin/operations/storage-migration',
        '/admin/operations/trash',
        '/admin/operations/ocr',
        '/admin/operations/runs',
        '/admin/operations/assets/'.$this->asset->id.'/versions',
    ];

    foreach ($pages as $page) {
        $response = $this->actingAs($this->admin)->get($page);
        $response->assertOk();
        $response->assertSee('Archiefbewerkingen', false);
        $response->assertSee(route('admin.operations.trash.index'), false);
        $response->assertSee(route('admin.operations.runs.index'), false);
    }
});

it('never advertises an operations page the signed-in user may not open', function (): void {
    // The viewer holds assets.view only: OCR reading and the photo overview are
    // permitted, everything requiring management rights must be absent.
    $response = $this->actingAs($this->viewer)->get('/admin/operations/ocr');
    $response->assertOk();

    $response->assertDontSee(route('admin.operations.diagnostics'), false);
    $response->assertDontSee(route('admin.operations.storage.index'), false);
    $response->assertDontSee(route('admin.operations.trash.index'), false);
    $response->assertDontSee(route('admin.operations.integrity.index'), false);
    $response->assertDontSee(route('admin.operations.duplicates.index'), false);
    $response->assertDontSee(route('admin.operations.runs.index'), false);

    $response->assertSee(route('admin.operations.ocr.index'), false);
    $response->assertSee(route('admin.assets.index'), false);

    // And the links it hides really are forbidden, so nothing was hidden arbitrarily.
    $this->actingAs($this->viewer)->get('/admin/operations/storage-migration')->assertForbidden();
    $this->actingAs($this->viewer)->get('/admin/operations/trash')->assertForbidden();
});

it('reaches the asset-scoped operations from the photo detail page', function (): void {
    $upload = QuarantineUpload::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'quarantine/nav.jpg',
        'original_filename' => 'nav.jpg',
        'byte_size' => 9,
        'status' => 'failed',
        'uploaded_by_user_id' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)->get('/admin/assets/'.$this->asset->id);
    $response->assertOk();

    $response->assertSee(route('admin.operations.versions.index', $this->asset), false);
    $response->assertSee(route('admin.operations.processing.show', $upload), false);
    $response->assertSee(route('admin.operations.runs.index'), false);
    // Dispatching OCR and moving a photo to the trash exist nowhere else in the UI.
    $response->assertSee(route('admin.operations.ocr.dispatch', $this->asset), false);
    $response->assertSee(route('admin.operations.trash.trash', $this->asset), false);
});

it('hides asset-scoped management actions from a viewer while keeping read access', function (): void {
    // Private ownership: a viewer only reaches its own dossier at all.
    $own = Asset::query()->create([
        'title' => 'Eigen Foto',
        'accession_number' => 'FA-NAV-002',
        'created_by_user_id' => $this->viewer->id,
    ]);

    $this->actingAs($this->viewer)->get('/admin/assets/'.$this->asset->id)->assertForbidden();
    $this->actingAs($this->viewer)->get('/admin/operations/assets/'.$this->asset->id.'/versions')->assertForbidden();

    $response = $this->actingAs($this->viewer)->get('/admin/assets/'.$own->id);
    $response->assertOk();

    $response->assertSee(route('admin.operations.versions.index', $own), false);
    $response->assertDontSee(route('admin.operations.ocr.dispatch', $own), false);
    $response->assertDontSee(route('admin.operations.trash.trash', $own), false);

    $this->actingAs($this->viewer)
        ->post(route('admin.operations.trash.trash', $own), ['reason' => 'Poging zonder recht'])
        ->assertForbidden();
});

it('finds the OCR result for one photo by the accession number the detail page links with', function (): void {
    AssetOcrText::query()->create([
        'asset_id' => $this->asset->id,
        'asset_file_id' => $this->file->id,
        'extracted_text' => 'Volstrekt andere woorden dan het aanwinstnummer',
        'status' => 'completed',
        'language' => 'nld',
        'processed_at' => now(),
    ]);

    $response = $this->actingAs($this->admin)->get('/admin/operations/ocr?q=FA-NAV-001');
    $response->assertOk();
    $response->assertSee('FA-NAV-001');
    $response->assertSee('Volstrekt andere woorden');
});

it('keeps wide operations tables inside a scroll container so a 390 px phone never scrolls sideways', function (): void {
    QuarantineUpload::query()->create([
        'asset_id' => $this->asset->id,
        'storage_disk' => 'local',
        'storage_key' => 'quarantine/nav.jpg',
        'original_filename' => 'nav.jpg',
        'byte_size' => 9,
        'status' => 'rejected',
        'uploaded_by_user_id' => $this->admin->id,
        'detected_sha256' => hash('sha256', 'nav-bytes'),
        'duplicate_of_asset_id' => $this->asset->id,
    ]);

    AssetOcrText::query()->create([
        'asset_id' => $this->asset->id,
        'asset_file_id' => $this->file->id,
        'extracted_text' => 'Herkende tekst',
        'status' => 'completed',
        'language' => 'nld',
        'processed_at' => now(),
    ]);

    IntegrityCheck::query()->create([
        'asset_id' => $this->asset->id,
        'asset_file_id' => $this->file->id,
        'check_type' => 'full',
        'status' => 'missing_derivative',
        'expected_sha256' => $this->file->sha256,
        'actual_sha256' => $this->file->sha256,
    ]);

    StorageMigration::query()->create([
        'source_disk' => 'local',
        'target_disk' => 'archive',
        'status' => 'verified',
        'total_files' => 1,
        'copied_files' => 1,
        'verified_files' => 1,
        'failed_files' => 0,
        'initiated_by_user_id' => $this->admin->id,
    ]);

    $trashed = Asset::query()->create([
        'title' => 'Verwijderd dossier',
        'accession_number' => 'FA-NAV-003',
        'created_by_user_id' => $this->admin->id,
    ]);
    $trashed->forceFill([
        'deleted_at' => now()->subDays(2),
        'deleted_by_user_id' => $this->admin->id,
        'deletion_reason' => 'Dubbel ingevoerd',
    ])->save();

    OperationRun::query()->create([
        'id' => (string) Str::ulid(),
        'operation_type' => 'integrity.verify',
        'status' => 'completed',
        'requested_by_user_id' => $this->admin->id,
        'payload' => [],
        'total_items' => 1,
        'processed_items' => 1,
        'finished_at' => now(),
    ]);

    AssetVersion::query()->create([
        'asset_id' => $this->asset->id,
        'asset_file_id' => $this->file->id,
        'version_number' => 1,
        'change_type' => 'scan',
        'change_note' => 'Eerste scan',
    ]);

    // Six of these pages really did push the page to 840 px on a 390 px viewport
    // before the tables were wrapped; the column count only grows from here.
    $pagesWithTables = [
        '/admin/operations/duplicates',
        '/admin/operations/processing',
        '/admin/operations/integrity',
        '/admin/operations/storage-migration',
        '/admin/operations/trash',
        '/admin/operations/ocr',
        '/admin/operations/runs',
        '/admin/operations/assets/'.$this->asset->id.'/versions',
    ];

    foreach ($pagesWithTables as $page) {
        $response = $this->actingAs($this->admin)->get($page);
        $response->assertOk();

        $html = $response->getContent();
        $tables = substr_count((string) $html, '<table');
        $wrappers = substr_count((string) $html, 'ops-table-scroll');

        expect($tables)->toBeGreaterThan(0, "Geen tabel gevonden op {$page}.");
        expect($wrappers)->toBeGreaterThanOrEqual($tables, "Niet elke tabel op {$page} zit in een scrollcontainer.");
    }
});
