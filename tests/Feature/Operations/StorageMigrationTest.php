<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Jobs\StorageCleanupJob;
use App\Modules\ArchiveOperations\Jobs\StorageCopyJob;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Models\StorageMigration;
use App\Modules\ArchiveOperations\Services\OperationRunService;
use App\Modules\ArchiveOperations\Services\StorageMigrationService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
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

test('migration scopes source disks and refuses incomplete or changed targets', function (string $boundary): void {
    $asset = Asset::query()->create(['accession_number' => 'GUARDED-COPY', 'created_by_user_id' => $this->admin->id]);
    $file = AssetFile::query()->create([
        'asset_id' => $asset->id, 'storage_disk' => 'local', 'storage_key' => 'originals/guarded',
        'sha256' => hash('sha256', 'original'), 'media_type' => 'image/jpeg', 'byte_size' => 8,
        'derivatives' => ['derivatives/guarded'],
    ]);
    AssetFile::query()->create([
        'asset_id' => $asset->id, 'storage_disk' => 'unrelated', 'storage_key' => 'originals/unrelated',
        'sha256' => hash('sha256', 'unrelated'), 'media_type' => 'image/jpeg', 'byte_size' => 9, 'is_primary' => false,
    ]);
    Storage::disk('local')->put($file->storage_key, 'original');
    Storage::disk('local')->put('derivatives/guarded', 'preview');
    $service = app(StorageMigrationService::class);
    $migration = $service->prepareMigration('local', 's3', $this->admin);
    expect($migration->total_files)->toBe(1);
    if ($boundary === 'copy') {
        Storage::disk('local')->delete('derivatives/guarded');
        $service->relocateChunk($migration, null, 25);
        expect($service->finalizeMigration($migration)->status)->toBe('failed_verification');
        Storage::disk('local')->put('derivatives/guarded', 'preview');
        $service->relocateChunk($migration, null, 25);
        expect($service->finalizeMigration($migration)->status)->toBe('verified')
            ->and($migration->fresh()->failed_files)->toBe(0);
    } else {
        $service->relocateChunk($migration, null, 25);
        $service->finalizeMigration($migration);
        if ($boundary === 'cleanup') {
            $service->cutover($migration, $this->admin);
        }
        Storage::disk('s3')->put('derivatives/guarded', 'corrupt');
        expect(fn () => $boundary === 'cleanup'
            ? $service->cleanupSourceFiles($migration->fresh(), $this->admin)
            : $service->cutover($migration->fresh(), $this->admin))->toThrow(RuntimeException::class);
    }
    expect(Storage::disk('local')->get($file->storage_key))->toBe('original')
        ->and(Storage::disk('local')->get('derivatives/guarded'))->toBe('preview')
        ->and($file->fresh()->storage_disk)->toBe($boundary === 'cleanup' ? 's3' : 'local');
})->with(['copy', 'cutover', 'cleanup']);

test('unauthorized users cannot access storage migration', function (): void {
    $response = $this->actingAs($this->viewer)->get('/admin/operations/storage-migration');

    $response->assertForbidden();
});

test('cleanup refuses out-of-band file binding changes after cutover', function (string $change): void {
    $asset = Asset::query()->create(['accession_number' => 'CHANGED-CLEANUP', 'created_by_user_id' => $this->admin->id]);
    $file = AssetFile::query()->create([
        'asset_id' => $asset->id, 'storage_disk' => 'local', 'storage_key' => 'originals/binding',
        'sha256' => hash('sha256', 'original'), 'media_type' => 'image/jpeg', 'byte_size' => 8,
        'derivatives' => [],
    ]);
    Storage::disk('local')->put($file->storage_key, 'original');
    $service = app(StorageMigrationService::class);
    $migration = $service->startMigration('local', 's3', $this->admin);
    $service->cutover($migration, $this->admin);
    AssetFile::query()->whereKey($file->id)->update(match ($change) {
        'disk' => ['storage_disk' => 'local'],
        'key' => ['storage_key' => 'originals/replacement'],
        'checksum' => ['sha256' => hash('sha256', 'replacement')],
    });

    expect(fn () => $service->cleanupSourceFiles($migration->fresh(), $this->admin))
        ->toThrow(RuntimeException::class, __('operations.storage.source_changed'));
    expect(Storage::disk('local')->get('originals/binding'))->toBe('original')
        ->and($migration->fresh()->status)->toBe('cutover_completed')
        ->and($migration->fresh()->source_cleaned_at)->toBeNull();
})->with(['disk', 'key', 'checksum']);

test('verified receipts recover stale counters without copying and backfill legacy derivative checksums', function (): void {
    $asset = Asset::query()->create(['accession_number' => 'LEGACY-COPY', 'created_by_user_id' => $this->admin->id]);
    $file = AssetFile::query()->create([
        'asset_id' => $asset->id, 'storage_disk' => 'local', 'storage_key' => 'originals/legacy',
        'sha256' => hash('sha256', 'original'), 'media_type' => 'image/jpeg', 'byte_size' => 8,
        'derivatives' => ['derivatives/legacy'],
    ]);
    Storage::disk('local')->put($file->storage_key, 'original');
    Storage::disk('local')->put('derivatives/legacy', 'preview');
    $service = app(StorageMigrationService::class);
    $migration = $service->startMigration('local', 's3', $this->admin);
    $migration->update(['verified_files' => 0, 'copied_files' => 0, 'failed_files' => 1]);
    $migration->relocations()->update(['verified_derivatives' => null]);

    $service->relocateChunk($migration, $file->id, 25);
    expect($migration->fresh()->verified_files)->toBe(1)
        ->and($migration->fresh()->failed_files)->toBe(0);
    $service->cutover($service->finalizeMigration($migration), $this->admin);
    expect($migration->relocations()->sole()->verified_derivatives)->toBe([
        'derivatives/legacy' => hash('sha256', 'preview'),
    ]);
});

test('cleanup reports failed deletes and resumes after partial deletion without trusting corrupt targets', function (): void {
    $asset = Asset::query()->create(['accession_number' => 'CLEANUP-RETRY', 'created_by_user_id' => $this->admin->id]);
    $file = AssetFile::query()->create([
        'asset_id' => $asset->id, 'storage_disk' => 'local', 'storage_key' => 'originals/retry',
        'sha256' => hash('sha256', 'original'), 'media_type' => 'image/jpeg', 'byte_size' => 8,
        'derivatives' => ['derivatives/retry'],
    ]);
    $source = Storage::disk('local');
    $source->put($file->storage_key, 'original');
    $source->put('derivatives/retry', 'preview');
    $service = app(StorageMigrationService::class);
    $migration = $service->startMigration('local', 's3', $this->admin);
    $service->cutover($migration, $this->admin);

    $failingSource = Mockery::mock($source);
    $failingSource->shouldReceive('delete')->with($file->storage_key)->once()->andReturnUsing(fn (): bool => $source->delete($file->storage_key));
    $failingSource->shouldReceive('delete')->with('derivatives/retry')->once()->andReturn(false);
    Storage::set('local', $failingSource);
    try {
        expect(fn () => $service->cleanupSourceFiles($migration->fresh(), $this->admin))
            ->toThrow(RuntimeException::class, __('operations.storage.cleanup_failed'));
    } finally {
        Storage::set('local', $source);
    }
    expect($source->exists($file->storage_key))->toBeFalse()
        ->and($source->get('derivatives/retry'))->toBe('preview')
        ->and($migration->fresh()->status)->toBe('cutover_completed')
        ->and($migration->fresh()->source_cleaned_at)->toBeNull();

    Storage::disk('s3')->put('derivatives/retry', 'corrupt');
    expect(fn () => $service->cleanupSourceFiles($migration->fresh(), $this->admin))->toThrow(RuntimeException::class);
    expect($source->get('derivatives/retry'))->toBe('preview');
    Storage::disk('s3')->put('derivatives/retry', 'preview');
    expect($service->cleanupSourceFiles($migration->fresh(), $this->admin))->toBe(1)
        ->and($migration->fresh()->status)->toBe('completed')
        ->and($source->exists('derivatives/retry'))->toBeFalse()
        ->and(Storage::disk('s3')->get($file->storage_key))->toBe('original');
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

    // Cleanup is queued, never executed in the request; the job removes the bytes.
    $response = $this->actingAs($this->admin)->post("/admin/operations/storage-migration/{$migration->id}/cleanup");
    $response->assertRedirect('/admin/operations/storage-migration');

    expect(Storage::disk('local')->exists($storageKey))->toBeTrue();

    $cleanupRun = OperationRunDriver::driveLatest(StorageCleanupJob::TYPE);
    expect($cleanupRun->status)->toBe(OperationRun::STATUS_COMPLETED);

    // Now local files should be removed
    expect(Storage::disk('local')->exists($storageKey))->toBeFalse();
    expect(Storage::disk('local')->exists($previewKey))->toBeFalse();

    // S3 files remain untouched and intact
    expect(Storage::disk('s3')->exists($storageKey))->toBeTrue();
    expect(Storage::disk('s3')->get($storageKey))->toBe($content);
});

test('starting a migration only queues work and the job copies and verifies every byte', function (): void {
    $asset = Asset::create([
        'title' => 'Queued Migration Asset',
        'accession_number' => 'FA-MIG-002',
        'created_by_user_id' => $this->admin->id,
    ]);

    $content = str_repeat('archival-scan-bytes', 512);
    $storageKey = 'originals/queued.jpg';
    Storage::disk('local')->put($storageKey, $content);

    AssetFile::create([
        'asset_id' => $asset->id,
        'storage_key' => $storageKey,
        'sha256' => hash('sha256', $content),
        'media_type' => 'image/jpeg',
        'byte_size' => strlen($content),
        'original_filename' => 'queued.jpg',
        'derivatives' => [],
        'is_primary' => true,
    ]);

    $response = $this->actingAs($this->admin)->post('/admin/operations/storage-migration/start', [
        'source_disk' => 'local',
        'target_disk' => 's3',
    ]);
    $response->assertRedirect('/admin/operations/storage-migration');

    // The request copied nothing: the bytes only move once the worker runs.
    expect(Storage::disk('s3')->exists($storageKey))->toBeFalse();

    $run = OperationRun::query()->where('operation_type', StorageCopyJob::TYPE)->latest('created_at')->firstOrFail();
    expect($run->status)->toBe(OperationRun::STATUS_QUEUED);
    expect($run->total_items)->toBe(1);

    $run = OperationRunDriver::drive($run);

    expect($run->status)->toBe(OperationRun::STATUS_COMPLETED);
    expect($run->failed_items)->toBe(0);
    expect(Storage::disk('s3')->get($storageKey))->toBe($content);
    expect(Storage::disk('local')->exists($storageKey))->toBeTrue();

    $migration = StorageMigration::query()->latest('id')->firstOrFail();
    expect($migration->status)->toBe('verified');
    expect($migration->verified_files)->toBe(1);
});

test('interrupted storage migration can be retried without source loss or premature cutover', function (): void {
    $asset = Asset::create([
        'title' => 'Interrupted Migration Asset',
        'accession_number' => 'FA-MIG-003',
        'created_by_user_id' => $this->admin->id,
    ]);

    $readyContent = 'ready archival bytes';
    $missingContent = 'bytes that appear before retry';
    $readyKey = 'originals/retry-ready.jpg';
    $missingKey = 'originals/retry-missing.jpg';
    Storage::disk('local')->put($readyKey, $readyContent);

    AssetFile::create([
        'asset_id' => $asset->id,
        'storage_key' => $readyKey,
        'sha256' => hash('sha256', $readyContent),
        'media_type' => 'image/jpeg',
        'byte_size' => strlen($readyContent),
        'original_filename' => 'retry-ready.jpg',
        'derivatives' => [],
        'is_primary' => true,
    ]);
    AssetFile::create([
        'asset_id' => $asset->id,
        'storage_key' => $missingKey,
        'sha256' => hash('sha256', $missingContent),
        'media_type' => 'image/jpeg',
        'byte_size' => strlen($missingContent),
        'original_filename' => 'retry-missing.jpg',
        'derivatives' => [],
        'is_primary' => false,
    ]);

    $migration = app(StorageMigrationService::class)->prepareMigration('local', 's3', $this->admin);
    $run = app(OperationRunService::class)->dispatchRun(
        StorageCopyJob::class,
        StorageCopyJob::TYPE,
        $this->admin,
        ['migration_id' => $migration->id],
        $migration->total_files,
    );

    $run = OperationRunDriver::drive($run);
    $migration->refresh();

    expect($run->status)->toBe(OperationRun::STATUS_COMPLETED)
        ->and($migration->status)->toBe('failed_verification')
        ->and($migration->verified_files)->toBe(1)
        ->and($migration->failed_files)->toBe(1)
        ->and(Storage::disk('local')->exists($readyKey))->toBeTrue()
        ->and(Storage::disk('s3')->get($readyKey))->toBe($readyContent);
    expect(fn () => app(StorageMigrationService::class)->cutover($migration, $this->admin))
        ->toThrow(RuntimeException::class, 'Alleen volledig geverifieerde migraties kunnen omgezet worden');

    Storage::disk('local')->put($missingKey, $missingContent);
    $retry = app(OperationRunService::class)->retryRun($run, StorageCopyJob::class, $this->admin);
    $retry = OperationRunDriver::drive($retry);
    $migration->refresh();

    expect($retry->status)->toBe(OperationRun::STATUS_COMPLETED)
        ->and($migration->status)->toBe('verified')
        ->and($migration->verified_files)->toBe(2)
        ->and($migration->failed_files)->toBe(0)
        ->and(Storage::disk('local')->exists($readyKey))->toBeTrue()
        ->and(Storage::disk('local')->exists($missingKey))->toBeTrue()
        ->and(Storage::disk('s3')->get($readyKey))->toBe($readyContent)
        ->and(Storage::disk('s3')->get($missingKey))->toBe($missingContent);

    app(StorageMigrationService::class)->cutover($migration, $this->admin);
    expect(Storage::disk('local')->exists($readyKey))->toBeTrue()
        ->and(Storage::disk('local')->exists($missingKey))->toBeTrue();
});
