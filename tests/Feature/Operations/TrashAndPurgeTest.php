<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Jobs\CleanupOrphanUploadsJob;
use App\Modules\ArchiveOperations\Jobs\PurgeAssetsJob;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Models\TrashPurgeLog;
use App\Modules\ArchiveOperations\Services\TrashService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Ingest\Models\QuarantineUpload;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OperationRunDriver;

beforeEach(function (): void {
    $this->artisan('migrate');

    $this->manageUsersPermission = Permission::query()->firstOrCreate(['key' => 'users.manage'], ['name' => 'Users Manage']);
    $this->manageCataloguePermission = Permission::query()->firstOrCreate(['key' => 'catalogue.manage'], ['name' => 'Catalogue Manage']);
    $this->viewAssetsPermission = Permission::query()->firstOrCreate(['key' => 'assets.view'], ['name' => 'Assets View']);

    $adminRole = Role::query()->firstOrCreate(['key' => 'administrator'], ['name' => 'Administrator']);
    $adminRole->permissions()->syncWithoutDetaching([$this->manageUsersPermission->id, $this->manageCataloguePermission->id, $this->viewAssetsPermission->id]);

    $archivistRole = Role::query()->firstOrCreate(['key' => 'archivist'], ['name' => 'Archivist']);
    $archivistRole->permissions()->syncWithoutDetaching([$this->manageCataloguePermission->id, $this->viewAssetsPermission->id]);

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

test('unauthorized users cannot access trash dashboard', function (): void {
    $response = $this->actingAs($this->viewer)->get('/admin/operations/trash');

    $response->assertForbidden();
});

test('soft-deleting an asset moves it to trash and excludes it from standard asset queries', function (): void {
    $asset = Asset::create([
        'title' => 'Herstelbaar Archiefitem',
        'accession_number' => 'FA-TRASH-001',
        'created_by_user_id' => $this->archivist->id,
    ]);

    expect(Asset::count())->toBe(1);

    $response = $this->actingAs($this->archivist)->from("/admin/assets/{$asset->id}")->post("/admin/operations/trash/assets/{$asset->id}/trash", [
        'reason' => 'Per ongeluk dubbel ingevoerd',
    ]);
    $response->assertRedirect('/admin/operations/trash');
    $this->get('/admin/operations/trash')->assertOk()->assertSee('FA-TRASH-001');

    // Standard queries now block this deleted asset
    expect(Asset::count())->toBe(0);
    expect(Asset::where('id', $asset->id)->first())->toBeNull();

    // Trash query finds the asset
    $trashed = Asset::onlyTrashed()->where('id', $asset->id)->first();
    expect($trashed)->not->toBeNull();
    expect($trashed->deletion_reason)->toBe('Per ongeluk dubbel ingevoerd');
    expect($trashed->deleted_by_user_id)->toBe($this->archivist->id);
    expect($trashed->deleted_at)->not->toBeNull();
});

test('restoring an asset from trash brings it back to active queries', function (): void {
    $asset = Asset::create([
        'title' => 'Herstel Item',
        'accession_number' => 'FA-TRASH-002',
        'created_by_user_id' => $this->archivist->id,
    ]);

    $service = app(TrashService::class);
    $service->moveToTrash($asset, 'Tijdelijk verwijderd', $this->archivist);

    expect(Asset::count())->toBe(0);

    $response = $this->actingAs($this->archivist)->post("/admin/operations/trash/assets/{$asset->id}/restore");
    $response->assertRedirect('/admin/operations/trash');

    // Asset is restored
    expect(Asset::count())->toBe(1);
    $restored = Asset::find($asset->id);
    expect($restored)->not->toBeNull();
    expect($restored->deleted_at)->toBeNull();
    expect($restored->deleted_by_user_id)->toBeNull();
    expect($restored->deletion_reason)->toBeNull();
});

test('permanent purge deletes physical files and logs audit trail', function (): void {
    $asset = Asset::create([
        'title' => 'Definitief Te Verwijderen',
        'accession_number' => 'FA-PURGE-001',
        'created_by_user_id' => $this->admin->id,
    ]);

    $content = 'Raw file data';
    $sha256 = hash('sha256', $content);
    $storageKey = 'originals/to-purge.jpg';
    $previewKey = 'derivatives/to-purge.jpg';

    Storage::disk('local')->put($storageKey, $content);
    Storage::disk('local')->put($previewKey, 'preview-content');

    AssetFile::create([
        'asset_id' => $asset->id,
        'storage_key' => $storageKey,
        'sha256' => $sha256,
        'media_type' => 'image/jpeg',
        'byte_size' => strlen($content),
        'original_filename' => 'to-purge.jpg',
        'derivatives' => [$previewKey],
        'is_primary' => true,
    ]);

    $service = app(TrashService::class);
    $service->moveToTrash($asset, 'Klaar voor purge', $this->admin);

    // Non-admin archivist cannot purge
    $response = $this->actingAs($this->archivist)->delete("/admin/operations/trash/assets/{$asset->id}/purge", [
        'reason' => 'Definitief verwijderen',
        'confirm_purge' => '1',
    ]);
    $response->assertForbidden();

    // Admin performs permanent purge
    $response = $this->actingAs($this->admin)->delete("/admin/operations/trash/assets/{$asset->id}/purge", [
        'reason' => 'Gerechtvaardigde definitieve vernietiging conform AVG',
        'confirm_purge' => '1',
    ]);
    $response->assertRedirect('/admin/operations/trash');

    // The request destroyed nothing; the queued job owns the irreversible work.
    expect(Storage::disk('local')->exists($storageKey))->toBeTrue();
    expect(Asset::withTrashed()->find($asset->id))->not->toBeNull();

    $run = OperationRunDriver::driveLatest(PurgeAssetsJob::TYPE);
    expect($run->status)->toBe(OperationRun::STATUS_COMPLETED);

    // Physical files are deleted
    expect(Storage::disk('local')->exists($storageKey))->toBeFalse();
    expect(Storage::disk('local')->exists($previewKey))->toBeFalse();

    // Asset and file records are completely gone
    expect(Asset::withTrashed()->find($asset->id))->toBeNull();
    expect(AssetFile::where('storage_key', $storageKey)->first())->toBeNull();

    // Purge log is recorded
    $log = TrashPurgeLog::where('accession_number', 'FA-PURGE-001')->first();
    expect($log)->not->toBeNull();
    expect($log->purged_by_user_id)->toBe($this->admin->id);
    expect($log->deleted_files_count)->toBe(1);
    expect($log->reason)->toBe('Gerechtvaardigde definitieve vernietiging conform AVG');
});

test('batch purging expired trash and cleaning orphan quarantine uploads', function (): void {
    // 1. Expired asset (older than 30 days)
    $expiredAsset = Asset::create([
        'title' => 'Oud Verwijderd Item',
        'accession_number' => 'FA-OLD-001',
        'created_by_user_id' => $this->admin->id,
    ]);
    $expiredAsset->deleted_at = now()->subDays(35);
    $expiredAsset->save();

    // 2. Fresh trashed asset (only 5 days old)
    $freshAsset = Asset::create([
        'title' => 'Recent Verwijderd Item',
        'accession_number' => 'FA-RECENT-001',
        'created_by_user_id' => $this->admin->id,
    ]);
    $freshAsset->deleted_at = now()->subDays(5);
    $freshAsset->save();

    // 3. Orphan quarantine upload
    Storage::disk('local')->put('quarantine/orphan.jpg', 'temp-data');
    QuarantineUpload::create([
        'asset_id' => $freshAsset->id,
        'storage_disk' => 'local',
        'storage_key' => 'quarantine/orphan.jpg',
        'original_filename' => 'orphan.jpg',
        'byte_size' => 100,
        'status' => 'failed',
    ]);

    // Batch purge expired
    $response = $this->actingAs($this->admin)->post('/admin/operations/trash/purge-expired', [
        'retention_days' => 30,
    ]);
    $response->assertRedirect('/admin/operations/trash');

    $run = OperationRunDriver::driveLatest(PurgeAssetsJob::TYPE);
    expect($run->status)->toBe(OperationRun::STATUS_COMPLETED);

    expect(Asset::withTrashed()->find($expiredAsset->id))->toBeNull();
    expect(Asset::withTrashed()->find($freshAsset->id))->not->toBeNull();

    // Cleanup orphan uploads
    $response = $this->actingAs($this->admin)->post('/admin/operations/trash/cleanup-orphans');
    $response->assertRedirect('/admin/operations/trash');

    $orphanRun = OperationRunDriver::driveLatest(CleanupOrphanUploadsJob::TYPE);
    expect($orphanRun->status)->toBe(OperationRun::STATUS_COMPLETED);

    expect(Storage::disk('local')->exists('quarantine/orphan.jpg'))->toBeFalse();
    expect(QuarantineUpload::where('storage_key', 'quarantine/orphan.jpg')->first())->toBeNull();
});
