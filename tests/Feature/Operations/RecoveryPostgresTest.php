<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Models\RestoreDrill;
use App\Modules\ArchiveOperations\Services\BackupRegisterService;
use App\Modules\ArchiveOperations\Services\DisposableRestoreDrillService;
use App\Modules\ArchiveOperations\Services\RestoreAcceptanceService;
use App\Modules\ArchiveOperations\Services\RestoreDrillService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Installation\InstallationSettings;
use App\Modules\Installation\InstallationState;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;

it('restores a real PostgreSQL backup and byte-identical originals into isolated empty targets', function (): void {
    app()->setLocale('en');
    $database = (string) getenv('FOTOARCHIEF_TEST_RECOVERY_DATABASE');
    if ($database === '') {
        $this->markTestSkipped('Requires an explicitly disposable PostgreSQL source ending _recovery_test and pg_dump/pg_restore on PATH.');
    }
    if (! str_ends_with($database, '_recovery_test')) {
        throw new RuntimeException('Refusing non-disposable recovery test database.');
    }
    config([
        'database.default' => 'pgsql', 'database.connections.pgsql.host' => '127.0.0.1',
        'database.connections.pgsql.port' => getenv('FOTOARCHIEF_TEST_PGVECTOR_PORT') ?: '5432',
        'database.connections.pgsql.username' => getenv('FOTOARCHIEF_TEST_PGVECTOR_USER') ?: 'fotoarchief',
        'database.connections.pgsql.password' => getenv('FOTOARCHIEF_TEST_PGVECTOR_PASSWORD') ?: '', 'database.connections.pgsql.database' => $database,
    ]);
    DB::purge('pgsql');
    DB::statement('DROP SCHEMA IF EXISTS public CASCADE');
    DB::statement('CREATE SCHEMA public');
    Artisan::call('migrate', ['--force' => true]);
    $this->seed(DatabaseSeeder::class);
    $targetDatabase = 'proof_'.bin2hex(random_bytes(6)).'_restore_drill';
    DB::statement('CREATE DATABASE "'.$targetDatabase.'"');
    $directory = sys_get_temp_dir().'/fotoarchief-recovery-proof-'.bin2hex(random_bytes(8));
    File::makeDirectory($directory, 0700);
    try {
        $bytes = random_bytes(512);
        $user = User::query()->create(['name' => 'Disposable recovery reader', 'email' => 'restore@example.test', 'password' => Hash::make('synthetic-restore-passphrase')]);
        $user->roles()->attach(Role::query()->where('key', 'viewer')->firstOrFail());
        $asset = Asset::query()->create(['accession_number' => 'DRILL-PROOF', 'title' => 'Original', 'created_by_user_id' => $user->id]);
        AssetFile::query()->create([
            'asset_id' => $asset->id, 'storage_disk' => 'local', 'storage_key' => 'proof.jpg', 'sha256' => hash('sha256', $bytes),
            'byte_size' => strlen($bytes), 'media_type' => 'image/jpeg', 'is_primary' => true, 'scanner_status' => 'clean', 'ingest_status' => 'ready_private',
        ]);
        $source = $directory.'/backup';
        File::makeDirectory($source, 0700);
        $dump = new Process(['pg_dump', '--format=custom', '--no-owner', '--no-acl', '--file='.$source.'/database.dump', $database], null, [
            'PGHOST' => '127.0.0.1', 'PGPORT' => (string) config('database.connections.pgsql.port'), 'PGUSER' => (string) config('database.connections.pgsql.username'), 'PGPASSWORD' => (string) config('database.connections.pgsql.password'),
        ]);
        $dump->mustRun();
        $tar = new PharData($source.'/storage-app.tar');
        $tar->addFromString('app/private/proof.jpg', $bytes);
        $state = new InstallationState('synthetic-drill', 'base64:'.base64_encode(random_bytes(32)), hash('sha256', 'unused-code'), 'complete',
            new InstallationSettings('must-not-connect.invalid', 5432, 'production', 'production', 'never-use', 'prefer', 'local', '', '', '', '', '', false),
            hash('sha256', 'synthetic-settings'));
        $stateJson = json_encode($state, JSON_THROW_ON_ERROR);
        $tar->addFromString('app/installation/state.json', $stateJson);
        unset($tar);
        File::put($source.'/VERSION', trim(File::get(base_path('VERSION')))."\n");
        File::put($source.'/FORMAT', "fotoarchief-local-backup-v2\n");
        File::put($source.'/BACKUP-MANIFEST.json', json_encode([
            'schema_version' => 2,
            'format' => 'fotoarchief-local-backup-v2',
            'app_version' => trim(File::get(base_path('VERSION'))),
            'created_at' => now()->toAtomString(),
            'components' => [
                'database.dump' => ['sha256' => hash_file('sha256', $source.'/database.dump'), 'bytes' => filesize($source.'/database.dump')],
                'storage-app.tar' => ['sha256' => hash_file('sha256', $source.'/storage-app.tar'), 'bytes' => filesize($source.'/storage-app.tar')],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
        $manifest = '';
        foreach (['database.dump', 'storage-app.tar', 'VERSION', 'FORMAT', 'BACKUP-MANIFEST.json'] as $file) {
            $manifest .= hash_file('sha256', $source.'/'.$file).'  '.$file."\n";
        }
        File::put($source.'/SHA256SUMS', $manifest);
        $backup = app(BackupRegisterService::class)->register($source);
        $service = app(RestoreDrillService::class);
        expect(fn () => $service->run($backup, $database, $directory.'/unsafe', true))->toThrow(RuntimeException::class, 'separate PostgreSQL');
        expect(fn () => $service->run($backup, $targetDatabase, $directory.'/unconfirmed', false))->toThrow(RuntimeException::class, 'Explicit confirmation');
        expect(fn () => $service->run($backup, $targetDatabase, $source.'/nested', true))->toThrow(RuntimeException::class, 'outside');
        $probe = DB::connectUsing('drill_probe', [...DB::connection()->getConfig(), 'database' => $targetDatabase], true);
        $probe->statement('CREATE SEQUENCE probe');
        expect(fn () => $service->run($backup, $targetDatabase, $directory.'/sequence', true))->toThrow(RuntimeException::class, 'not empty');
        $probe->statement('DROP SEQUENCE probe');
        DB::purge('drill_probe');
        $drill = $service->run($backup, $targetDatabase, $directory.'/restored', true);
        expect($drill->status)->toBe('verified')->and($drill->report['verified_originals'])->toBe(1)
            ->and($drill->report['application_login_verified'])->toBeFalse()
            ->and(File::get($directory.'/restored/storage/app/private/proof.jpg'))->toBe($bytes)
            ->and(Asset::query()->whereKey($asset->id)->value('title'))->toBe('Original');
        $acceptance = app(RestoreAcceptanceService::class);
        $credentials = ['email' => $user->email, 'password' => 'synthetic-restore-passphrase'];
        expect(fn () => $acceptance->run($drill, $asset->id, $credentials, false))->toThrow(RuntimeException::class);
        expect(fn () => $acceptance->run($drill, $asset->id, ['email' => $user->email, 'password' => 'wrong'], true))->toThrow(RuntimeException::class);
        $report = $acceptance->run($drill, $asset->id, $credentials, true);
        expect($report['application_login_verified'])->toBeTrue()->and($report['installer_locked'])->toBeTrue()
            ->and($report['authorized_asset_verified'])->toBeTrue()->and($report['transport'])->toBe('loopback-http')
            ->and($drill->fresh()->report['application_login_verified'])->toBeTrue()
            ->and(File::get($directory.'/restored/storage/app/installation/state.json'))->toBe($stateJson)
            ->and(glob($directory.'/restored/.acceptance-*'))->toBe([])
            ->and(DB::table('acceptance_evidence')->where('source', 'test-installation')->where('result', 'passed')->count())->toBe(1)
            ->and(DB::table('acceptance_evidence')->where('result', 'failed')->count())->toBe(1)
            ->and(DB::connection()->getDatabaseName())->toBe($database);
        expect(fn () => $acceptance->run($drill, $asset->id, ['email' => $user->email, 'password' => 'wrong'], true))->toThrow(RuntimeException::class);
        expect($drill->fresh()->report['application_login_verified'])->toBeFalse()
            ->and($drill->fresh()->report['acceptance_result'])->toBe('failed')
            ->and(DB::table('acceptance_evidence')->where('result', 'passed')->count())->toBe(1)
            ->and(DB::table('acceptance_evidence')->where('result', 'failed')->count())->toBe(2)
            ->and(glob($directory.'/restored/.acceptance-*'))->toBe([]);
        expect(fn () => $service->run($backup, $targetDatabase, $directory.'/second', true))->toThrow(RuntimeException::class, 'not empty');
        $disposable = app(DisposableRestoreDrillService::class)->runLatest($directory, true);
        expect($disposable->status)->toBe('verified')
            ->and($disposable->report['disposable_cleanup'])->toBe('verified')
            ->and(file_exists($disposable->target_directory))->toBeFalse();
        expect(file_exists($directory.'/second'))->toBeFalse()
            ->and(RestoreDrill::query()->where('status', 'failed')->count())->toBe(2)
            ->and(RestoreDrill::query()->where('status', 'verified')->count())->toBe(2);
        DB::statement('DROP DATABASE "'.$targetDatabase.'"');
        DB::statement('CREATE DATABASE "'.$targetDatabase.'"');
        $tar = new PharData($source.'/storage-app.tar');
        $tar->delete('app/private/proof.jpg');
        $tar->addFromString('app/private/proof.jpg', 'wrong original bytes');
        unset($tar);
        File::put($source.'/BACKUP-MANIFEST.json', json_encode([
            'schema_version' => 2,
            'format' => 'fotoarchief-local-backup-v2',
            'app_version' => trim(File::get(base_path('VERSION'))),
            'created_at' => now()->toAtomString(),
            'components' => [
                'database.dump' => ['sha256' => hash_file('sha256', $source.'/database.dump'), 'bytes' => filesize($source.'/database.dump')],
                'storage-app.tar' => ['sha256' => hash_file('sha256', $source.'/storage-app.tar'), 'bytes' => filesize($source.'/storage-app.tar')],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
        $manifest = '';
        foreach (['database.dump', 'storage-app.tar', 'VERSION', 'FORMAT', 'BACKUP-MANIFEST.json'] as $file) {
            $manifest .= hash_file('sha256', $source.'/'.$file).'  '.$file."\n";
        }
        File::put($source.'/SHA256SUMS', $manifest);
        $corrupt = app(BackupRegisterService::class)->register($source);
        expect(fn () => $service->run($corrupt, $targetDatabase, $directory.'/corrupt', true))->toThrow(RuntimeException::class, 'checksum or byte size mismatch');
        expect(RestoreDrill::query()->where('status', 'verified')->count())->toBe(2);
        File::put($source.'/database.dump', 'tampered');
        expect(fn () => $service->run($backup, $targetDatabase, $directory.'/tampered', true))->toThrow(RuntimeException::class, 'manifest');
        expect(RestoreDrill::query()->where('status', 'failed')->count())->toBe(4);
    } finally {
        DB::statement('DROP DATABASE "'.$targetDatabase.'"');
        DB::disconnect('pgsql');
        File::deleteDirectory($directory);
    }
});
