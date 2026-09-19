<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Models\BackupRecord;
use App\Modules\ArchiveOperations\Models\StorageMigration;
use App\Modules\ArchiveOperations\Services\BackupRegisterService;
use App\Modules\ArchiveOperations\Services\EncryptedOffsiteCopyService;
use App\Modules\ArchiveOperations\Services\StorageMigrationService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::query()->create(['name' => 'Recovery admin', 'email' => 'hardening@example.test', 'password' => 'unused']);
    $this->admin->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
});

it('registers a version-bound structured backup manifest and rejects component drift', function (): void {
    $directory = sys_get_temp_dir().'/fotoarchief-manifest-v2-'.bin2hex(random_bytes(8));
    File::makeDirectory($directory, 0700);
    try {
        File::put($directory.'/database.dump', 'database');
        File::put($directory.'/storage-app.tar', 'storage');
        File::put($directory.'/VERSION', trim(File::get(base_path('VERSION')))."\n");
        File::put($directory.'/FORMAT', "fotoarchief-local-backup-v2\n");
        File::put($directory.'/BACKUP-MANIFEST.json', json_encode([
            'schema_version' => 2,
            'format' => 'fotoarchief-local-backup-v2',
            'app_version' => trim(File::get(base_path('VERSION'))),
            'created_at' => '2026-10-12T12:00:00+00:00',
            'components' => [
                'database.dump' => ['sha256' => hash_file('sha256', $directory.'/database.dump'), 'bytes' => filesize($directory.'/database.dump')],
                'storage-app.tar' => ['sha256' => hash_file('sha256', $directory.'/storage-app.tar'), 'bytes' => filesize($directory.'/storage-app.tar')],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
        $checksums = '';
        foreach (['database.dump', 'storage-app.tar', 'VERSION', 'FORMAT', 'BACKUP-MANIFEST.json'] as $file) {
            $checksums .= hash_file('sha256', $directory.'/'.$file).'  '.$file."\n";
        }
        File::put($directory.'/SHA256SUMS', $checksums);

        $backup = app(BackupRegisterService::class)->register($directory);
        expect($backup)->toBeInstanceOf(BackupRecord::class)
            ->and($backup->manifest_schema_version)->toBe(2)
            ->and($backup->backup_format)->toBe('fotoarchief-local-backup-v2')
            ->and($backup->manifest['app_version'])->toBe(trim(File::get(base_path('VERSION'))));

        File::put($directory.'/database.dump', 'drifted');
        expect(fn () => app(BackupRegisterService::class)->inspect($directory))->toThrow(RuntimeException::class);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('copies only checksum-verified encrypted bytes through a private filesystem adapter', function (): void {
    Storage::fake('offsite');
    config(['recovery.offsite_disk' => 'offsite']);
    $directory = sys_get_temp_dir().'/fotoarchief-offsite-'.bin2hex(random_bytes(8));
    File::makeDirectory($directory, 0700);
    try {
        $encrypted = $directory.'/backup.tar.gz.enc';
        File::put($encrypted, random_bytes(128));
        File::put($encrypted.'.manifest', 'encrypted_sha256='.hash_file('sha256', $encrypted)."\n");

        $result = app(EncryptedOffsiteCopyService::class)->copy($encrypted, 'archives/backup.tar.gz.enc');
        expect($result['disk'])->toBe('offsite')
            ->and($result['bytes'])->toBe(128);
        Storage::disk('offsite')->assertExists([
            'archives/backup.tar.gz.enc',
            'archives/backup.tar.gz.enc.manifest',
        ]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('dry-runs connectivity and capacity without creating a migration or moving bytes', function (): void {
    Storage::fake('source');
    Storage::fake('target');
    config([
        'filesystems.disks.source' => ['driver' => 'local', 'root' => Storage::disk('source')->path('')],
        'filesystems.disks.target' => ['driver' => 'local', 'root' => Storage::disk('target')->path('')],
    ]);
    $asset = Asset::query()->create(['accession_number' => 'PREFLIGHT', 'created_by_user_id' => $this->admin->id]);
    AssetFile::query()->create([
        'asset_id' => $asset->id,
        'storage_disk' => 'source',
        'storage_key' => 'originals/preflight.jpg',
        'sha256' => hash('sha256', 'original'),
        'media_type' => 'image/jpeg',
        'byte_size' => 8,
    ]);

    $report = app(StorageMigrationService::class)->preflightMigration('source', 'target');
    expect($report['ready'])->toBeTrue()
        ->and($report['file_count'])->toBe(1)
        ->and($report['required_bytes'])->toBe(8)
        ->and($report['required_with_headroom'])->toBe(9)
        ->and($report['capacity'])->toBe('sufficient')
        ->and(StorageMigration::query()->count())->toBe(0)
        ->and(Storage::disk('target')->allFiles())->toBe([]);
});

it('exposes the storage preflight as a read-only administrator action', function (): void {
    Storage::fake('source');
    Storage::fake('target');
    config([
        'filesystems.disks.source' => ['driver' => 'local', 'root' => Storage::disk('source')->path('')],
        'filesystems.disks.target' => ['driver' => 'local', 'root' => Storage::disk('target')->path('')],
    ]);

    $response = $this->actingAs($this->admin)->post('/admin/operations/storage-migration/preflight', [
        'source_disk' => 'source',
        'target_disk' => 'target',
    ]);

    $response->assertRedirect('/admin/operations/storage-migration')
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'Dry-run geslaagd'));
    expect(StorageMigration::query()->count())->toBe(0)
        ->and(Storage::disk('target')->allFiles())->toBe([]);
});
