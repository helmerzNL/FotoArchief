<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Jobs\StorageCopyJob;
use App\Modules\ArchiveOperations\Services\OperationRunService;
use App\Modules\ArchiveOperations\Services\StorageMigrationService;
use App\Modules\ArchiveOperations\Services\TrashService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

it('recovers real storage bytes and counts after worker exit at the :dataset boundary', function (string $boundary): void {
    $root = sys_get_temp_dir().'/fotoarchief-storage-process-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($root);
    File::put($root.'/.disposable', 'storage recovery fixture');
    File::put($root.'/database.sqlite', '');
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $root.'/database.sqlite',
        'filesystems.disks.local.root' => $root.'/source',
        'filesystems.disks.recovery' => ['driver' => 'local', 'root' => $root.'/target', 'throw' => true],
    ]);
    DB::purge('sqlite');
    Storage::forgetDisk(['local', 'recovery']);
    try {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
        $user = User::query()->create(['name' => 'Recovery fixture', 'email' => 'recovery@example.test', 'password' => 'disposable-recovery-password']);
        $user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
        foreach ([1, 2] as $index) {
            $asset = Asset::query()->create(['accession_number' => 'PROCESS-'.$index, 'title' => 'Recovery '.$index, 'created_by_user_id' => $user->id]);
            $original = 'originals/'.$index.'.bin';
            $preview = 'derivatives/'.$index.'.jpg';
            Storage::disk('local')->put($original, 'original bytes '.$index);
            Storage::disk('local')->put($preview, 'preview bytes '.$index);
            AssetFile::query()->create([
                'asset_id' => $asset->id, 'storage_disk' => 'local', 'storage_key' => $original,
                'sha256' => hash('sha256', 'original bytes '.$index), 'media_type' => 'image/jpeg',
                'byte_size' => 16, 'is_primary' => true, 'derivatives' => [$preview],
                'ingest_status' => 'ready_private', 'scanner_status' => 'clean',
            ]);
        }
        $service = app(StorageMigrationService::class);
        $migration = $service->prepareMigration('local', 'recovery', $user);
        $run = app(OperationRunService::class)->dispatchRun(StorageCopyJob::class, StorageCopyJob::TYPE, $user, ['migration_id' => $migration->id], 2);
        $worker = new Process([PHP_BINARY, base_path('tests/Support/storage-interrupted-worker.php'), $root, $run->id, $boundary], base_path(), timeout: 45);
        $worker->run();
        expect($worker->getExitCode())->toBe(73, $worker->getOutput().$worker->getErrorOutput());
        $verified = $migration->relocations()->where('is_verified', true)->count();
        expect($migration->fresh()->verified_files)->toBe($verified)->and($verified)->toBeGreaterThan(0);
        $run->refresh()->update(['started_at' => now()->subSeconds(151)]);
        (new StorageCopyJob($run->id))->handle();
        expect($run->fresh()->status)->toBe('completed')
            ->and($run->fresh()->processed_items)->toBe(2)
            ->and($run->fresh()->failed_items)->toBe(0)
            ->and($migration->fresh()->status)->toBe('verified')
            ->and($migration->fresh()->verified_files)->toBe(2)
            ->and($migration->relocations()->count())->toBe(2);
        foreach ([1, 2] as $index) {
            expect(Storage::disk('local')->get('originals/'.$index.'.bin'))->toBe('original bytes '.$index)
                ->and(Storage::disk('recovery')->get('originals/'.$index.'.bin'))->toBe('original bytes '.$index)
                ->and(Storage::disk('recovery')->get('derivatives/'.$index.'.jpg'))->toBe('preview bytes '.$index);
        }
        $service->cutover($migration->fresh(), $user);
        expect(AssetFile::query()->where('storage_disk', 'recovery')->count())->toBe(2)
            ->and(Storage::disk('local')->exists('originals/1.bin'))->toBeTrue();
        expect($service->cleanupSourceFiles($migration->fresh(), $user))->toBe(2);
        foreach ([1, 2] as $index) {
            expect(Storage::disk('local')->exists('originals/'.$index.'.bin'))->toBeFalse()
                ->and(Storage::disk('local')->exists('derivatives/'.$index.'.jpg'))->toBeFalse()
                ->and(Storage::disk('recovery')->get('originals/'.$index.'.bin'))->toBe('original bytes '.$index)
                ->and(Storage::disk('recovery')->get('derivatives/'.$index.'.jpg'))->toBe('preview bytes '.$index);
        }
        app(TrashService::class)->purgeAsset(Asset::query()->where('accession_number', 'PROCESS-1')->firstOrFail(), 'Disposable recovery test', $user);
        expect(Storage::disk('recovery')->exists('originals/1.bin'))->toBeFalse()
            ->and(Storage::disk('recovery')->exists('derivatives/1.jpg'))->toBeFalse()
            ->and(Storage::disk('recovery')->exists('originals/2.bin'))->toBeTrue();
    } finally {
        DB::disconnect('sqlite');
        File::deleteDirectory($root);
    }
})->with(['receipt', 'cursor']);
