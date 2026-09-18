<?php

declare(strict_types=1);

use App\Modules\ArchiveOperations\Models\RestoreDrill;
use App\Modules\ArchiveOperations\Services\BackupRegisterService;
use App\Modules\ArchiveOperations\Services\RestoreDrillService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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
    $targetDatabase = 'proof_'.bin2hex(random_bytes(6)).'_restore_drill';
    DB::statement('CREATE DATABASE "'.$targetDatabase.'"');
    $directory = sys_get_temp_dir().'/fotoarchief-recovery-proof-'.bin2hex(random_bytes(8));
    File::makeDirectory($directory, 0700);
    try {
        $bytes = random_bytes(512);
        $asset = Asset::query()->create(['accession_number' => 'DRILL-PROOF', 'title' => 'Original']);
        AssetFile::query()->create([
            'asset_id' => $asset->id, 'storage_disk' => 'local', 'storage_key' => 'proof.jpg', 'sha256' => hash('sha256', $bytes),
            'byte_size' => strlen($bytes), 'media_type' => 'image/jpeg', 'is_primary' => true,
        ]);
        $source = $directory.'/backup';
        File::makeDirectory($source, 0700);
        $dump = new Process(['pg_dump', '--format=custom', '--no-owner', '--no-acl', '--file='.$source.'/database.dump', $database], null, [
            'PGHOST' => '127.0.0.1', 'PGPORT' => (string) config('database.connections.pgsql.port'), 'PGUSER' => (string) config('database.connections.pgsql.username'), 'PGPASSWORD' => (string) config('database.connections.pgsql.password'),
        ]);
        $dump->mustRun();
        $tar = new PharData($source.'/storage-app.tar');
        $tar->addFromString('app/private/proof.jpg', $bytes);
        unset($tar);
        File::put($source.'/VERSION', trim(File::get(base_path('VERSION')))."\n");
        File::put($source.'/FORMAT', "fotoarchief-local-backup-v1\n");
        $manifest = '';
        foreach (['database.dump', 'storage-app.tar', 'VERSION', 'FORMAT'] as $file) {
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
        expect(fn () => $service->run($backup, $targetDatabase, $directory.'/second', true))->toThrow(RuntimeException::class, 'not empty');
        expect(file_exists($directory.'/second'))->toBeFalse()
            ->and(RestoreDrill::query()->where('status', 'failed')->count())->toBe(2)
            ->and(RestoreDrill::query()->where('status', 'verified')->count())->toBe(1);
        DB::statement('DROP DATABASE "'.$targetDatabase.'"');
        DB::statement('CREATE DATABASE "'.$targetDatabase.'"');
        unlink($source.'/storage-app.tar');
        $tar = new PharData($source.'/storage-app.tar');
        $tar->addFromString('app/private/proof.jpg', 'wrong original bytes');
        unset($tar);
        $manifest = '';
        foreach (['database.dump', 'storage-app.tar', 'VERSION', 'FORMAT'] as $file) {
            $manifest .= hash_file('sha256', $source.'/'.$file).'  '.$file."\n";
        }
        File::put($source.'/SHA256SUMS', $manifest);
        $corrupt = app(BackupRegisterService::class)->register($source);
        expect(fn () => $service->run($corrupt, $targetDatabase, $directory.'/corrupt', true))->toThrow(RuntimeException::class, 'checksum or byte size mismatch');
        expect(RestoreDrill::query()->where('status', 'verified')->count())->toBe(1);
        File::put($source.'/database.dump', 'tampered');
        expect(fn () => $service->run($backup, $targetDatabase, $directory.'/tampered', true))->toThrow(RuntimeException::class, 'manifest');
        expect(RestoreDrill::query()->where('status', 'failed')->count())->toBe(4);
    } finally {
        DB::statement('DROP DATABASE "'.$targetDatabase.'"');
        DB::disconnect('pgsql');
        File::deleteDirectory($directory);
    }
});
