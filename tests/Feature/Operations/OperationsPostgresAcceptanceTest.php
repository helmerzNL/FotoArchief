<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\ArchiveOperations\Jobs\OperationJob;
use App\Modules\ArchiveOperations\Jobs\ProcessAssetOcrJob;
use App\Modules\ArchiveOperations\Jobs\PurgeAssetsJob;
use App\Modules\ArchiveOperations\Jobs\StorageCopyJob;
use App\Modules\ArchiveOperations\Jobs\VerifyIntegrityJob;
use App\Modules\ArchiveOperations\Models\OperationRun;
use App\Modules\ArchiveOperations\Models\StorageMigration;
use App\Modules\ArchiveOperations\Models\TrashPurgeLog;
use App\Modules\ArchiveOperations\Services\FileVersionService;
use App\Modules\ArchiveOperations\Services\IntegrityVerificationService;
use App\Modules\ArchiveOperations\Services\StorageMigrationService;
use App\Modules\ArchiveOperations\Services\TesseractOcrService;
use App\Modules\ArchiveOperations\Services\TrashService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OperationRunDriver;

/**
 * Real-PostgreSQL acceptance for the ArchiveOperations module.
 *
 * These cases deliberately avoid database and storage fakes: the immutability trigger,
 * the timestamptz trash column and the checksum guard only exist on real PostgreSQL,
 * and a faked disk cannot prove that an original survived a derivative rebuild.
 */
final class OperationsPostgresAcceptance
{
    /** @var list<string> */
    public static array $tempDirectories = [];

    public static function bootDatabase(string $database): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => getenv('FOTOARCHIEF_TEST_PG_HOST') ?: '127.0.0.1',
            'database.connections.pgsql.port' => getenv('FOTOARCHIEF_TEST_PG_PORT') ?: 55449,
            'database.connections.pgsql.database' => $database,
            'database.connections.pgsql.username' => getenv('FOTOARCHIEF_TEST_PG_USER') ?: 'fotoarchief',
            'database.connections.pgsql.password' => getenv('FOTOARCHIEF_TEST_PG_PASSWORD') ?: '',
            'database.connections.pgsql.sslmode' => 'prefer',
        ]);
        DB::purge('pgsql');
        DB::connection('pgsql')->statement('DROP SCHEMA IF EXISTS public CASCADE');
        DB::connection('pgsql')->statement('CREATE SCHEMA public');
        Artisan::call('migrate', ['--force' => true]);
    }

    /**
     * Real private storage root on disk; no Storage::fake anywhere in this file.
     */
    public static function bootPrivateStorage(): string
    {
        $directory = sys_get_temp_dir().'/fotoarchief-ops-pg-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory.'/private');
        File::ensureDirectoryExists($directory.'/archive');
        config([
            'filesystems.disks.local.root' => $directory.'/private',
            'filesystems.disks.archive' => [
                'driver' => 'local',
                'root' => $directory.'/archive',
                'visibility' => 'private',
                'throw' => false,
            ],
        ]);
        Storage::forgetDisk('local');
        Storage::forgetDisk('archive');
        self::$tempDirectories[] = $directory;

        return $directory;
    }

    public static function cleanup(): void
    {
        foreach (self::$tempDirectories as $directory) {
            File::deleteDirectory($directory);
        }
        self::$tempDirectories = [];
        DB::disconnect('pgsql');
    }

    public static function administrator(string $email): User
    {
        $manageUsers = Permission::query()->firstOrCreate(['key' => 'users.manage'], ['name' => 'Users Manage']);
        $manageCatalogue = Permission::query()->firstOrCreate(['key' => 'catalogue.manage'], ['name' => 'Catalogue Manage']);
        $viewAssets = Permission::query()->firstOrCreate(['key' => 'assets.view'], ['name' => 'Assets View']);
        $updateAssets = Permission::query()->firstOrCreate(['key' => 'assets.update'], ['name' => 'Assets Update']);
        $role = Role::query()->firstOrCreate(['key' => 'administrator'], ['name' => 'Administrator']);
        $role->permissions()->syncWithoutDetaching([$manageUsers->id, $manageCatalogue->id, $viewAssets->id, $updateAssets->id]);

        $user = User::query()->create([
            'name' => 'Operations Acceptance',
            'email' => $email,
            'password' => Hash::make('disposable-operations-password'),
        ]);
        $user->roles()->attach($role);

        return $user;
    }

    /**
     * Produces a genuine JPEG byte stream, matching the media contract the ingest
     * pipeline enforces (JPEG/PNG/WebP in, JPEG previews out).
     */
    public static function jpegBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new \RuntimeException('Kon geen testafbeelding aanmaken.');
        }
        $ink = imagecolorallocate($image, 30, 90, 160);
        imagefilledrectangle($image, 0, 0, (int) ($width / 2), $height, $ink === false ? 0 : $ink);
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}

$requiresPostgres = fn (): bool => getenv('FOTOARCHIEF_TEST_PG_OPERATIONS_DATABASE') === false;

afterEach(function (): void {
    OperationsPostgresAcceptance::cleanup();
});

it('enforces the real asset_files immutability trigger and rebuilds JPEG derivatives from the preserved original', function (): void {
    $database = (string) getenv('FOTOARCHIEF_TEST_PG_OPERATIONS_DATABASE');
    expect($database)->toEndWith('_final_test');
    OperationsPostgresAcceptance::bootDatabase($database);
    OperationsPostgresAcceptance::bootPrivateStorage();

    $actor = OperationsPostgresAcceptance::administrator('immutability@example.test');
    $asset = Asset::query()->create([
        'accession_number' => 'PG-VERSION-001',
        'title' => 'Originele scan',
        'created_by_user_id' => $actor->id,
    ]);

    $originalBytes = OperationsPostgresAcceptance::jpegBytes(1400, 900);
    $originalSha = hash('sha256', $originalBytes);
    $originalKey = 'originals/'.$asset->id.'/scan-v1.jpg';
    Storage::disk('local')->put($originalKey, $originalBytes);

    $file = AssetFile::query()->create([
        'asset_id' => $asset->id,
        'storage_disk' => 'local',
        'storage_key' => $originalKey,
        'sha256' => $originalSha,
        'media_type' => 'image/jpeg',
        'byte_size' => strlen($originalBytes),
        'original_filename' => 'scan-v1.jpg',
        'is_primary' => true,
    ]);

    // The PostgreSQL trigger — not an Eloquent guard — must refuse identifier drift.
    expect(fn () => DB::transaction(fn () => DB::table('asset_files')
        ->where('id', $file->id)
        ->update(['sha256' => str_repeat('a', 64)])))->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => DB::table('asset_files')
        ->where('id', $file->id)
        ->update(['storage_key' => 'originals/tampered.jpg'])))->toThrow(QueryException::class);

    // Rebuild derivatives from the immutable original on real private storage.
    app(FileVersionService::class)->reprocessDerivatives($file->fresh(), $actor);

    $rebuilt = $file->fresh();
    expect($rebuilt->sha256)->toBe($originalSha)
        ->and($rebuilt->storage_key)->toBe($originalKey);

    $derivatives = (array) $rebuilt->derivatives;
    expect($derivatives)->toHaveKeys(['preview300', 'preview1200', 'preview2000']);

    foreach ($derivatives as $key) {
        expect(Storage::disk('local')->exists($key))->toBeTrue()
            ->and($key)->toEndWith('.jpg');
        // Each derivative is a decodable JPEG, not an empty or WebP placeholder.
        $info = getimagesizefromstring((string) Storage::disk('local')->get($key));
        expect($info)->not->toBeFalse()
            ->and($info[2])->toBe(IMAGETYPE_JPEG);
    }

    // The original bytes on disk are untouched byte-for-byte after the rebuild.
    expect(hash('sha256', (string) Storage::disk('local')->get($originalKey)))->toBe($originalSha);

    // Integrity verification agrees on real storage.
    $check = app(IntegrityVerificationService::class)->verifyFile($rebuilt);
    expect($check['status'])->toBe('ok');
})->group('postgres')->skip($requiresPostgres, 'Requires FOTOARCHIEF_TEST_PG_OPERATIONS_DATABASE ending in _final_test.');

it('copies and verifies a real file to a second disk and only deletes the source after cutover', function (): void {
    $database = (string) getenv('FOTOARCHIEF_TEST_PG_OPERATIONS_DATABASE');
    OperationsPostgresAcceptance::bootDatabase($database);
    OperationsPostgresAcceptance::bootPrivateStorage();

    $actor = OperationsPostgresAcceptance::administrator('migration@example.test');
    $asset = Asset::query()->create([
        'accession_number' => 'PG-MIGRATE-001',
        'title' => 'Te verhuizen scan',
        'created_by_user_id' => $actor->id,
    ]);

    $originalBytes = OperationsPostgresAcceptance::jpegBytes(900, 600);
    $originalSha = hash('sha256', $originalBytes);
    $originalKey = 'originals/'.$asset->id.'/migrate.jpg';
    $derivativeKey = 'derivatives/'.$asset->id.'/preview-300.jpg';
    Storage::disk('local')->put($originalKey, $originalBytes);
    Storage::disk('local')->put($derivativeKey, OperationsPostgresAcceptance::jpegBytes(300, 200));

    AssetFile::query()->create([
        'asset_id' => $asset->id,
        'storage_disk' => 'local',
        'storage_key' => $originalKey,
        'sha256' => $originalSha,
        'media_type' => 'image/jpeg',
        'byte_size' => strlen($originalBytes),
        'original_filename' => 'migrate.jpg',
        'derivatives' => ['preview300' => $derivativeKey],
        'is_primary' => true,
    ]);

    $service = app(StorageMigrationService::class);
    $migration = $service->startMigration('local', 'archive', $actor);

    expect($migration->status)->toBe('verified')
        ->and($migration->verified_files)->toBe(1)
        ->and($migration->failed_files)->toBe(0);

    // Bytes really landed on the second disk and hash to the same value.
    expect(Storage::disk('archive')->exists($originalKey))->toBeTrue()
        ->and(hash('sha256', (string) Storage::disk('archive')->get($originalKey)))->toBe($originalSha)
        ->and(Storage::disk('archive')->exists($derivativeKey))->toBeTrue();

    // Source survives verification; cleanup before cutover is refused.
    expect(Storage::disk('local')->exists($originalKey))->toBeTrue();
    expect(fn () => $service->cleanupSourceFiles($migration, $actor))->toThrow(\RuntimeException::class);
    expect(Storage::disk('local')->exists($originalKey))->toBeTrue();

    $service->cutover($migration->fresh(), $actor);
    expect(StorageMigration::query()->findOrFail($migration->id)->status)->toBe('cutover_completed');

    $deleted = $service->cleanupSourceFiles(StorageMigration::query()->findOrFail($migration->id), $actor);
    expect($deleted)->toBe(1)
        ->and(Storage::disk('local')->exists($originalKey))->toBeFalse()
        ->and(Storage::disk('local')->exists($derivativeKey))->toBeFalse()
        ->and(Storage::disk('archive')->exists($originalKey))->toBeTrue()
        ->and(hash('sha256', (string) Storage::disk('archive')->get($originalKey)))->toBe($originalSha);
})->group('postgres')->skip($requiresPostgres, 'Requires FOTOARCHIEF_TEST_PG_OPERATIONS_DATABASE ending in _final_test.');

it('hides trashed assets from every default predicate and destroys real files only on purge', function (): void {
    $database = (string) getenv('FOTOARCHIEF_TEST_PG_OPERATIONS_DATABASE');
    OperationsPostgresAcceptance::bootDatabase($database);
    OperationsPostgresAcceptance::bootPrivateStorage();

    $actor = OperationsPostgresAcceptance::administrator('trash@example.test');
    $asset = Asset::query()->create([
        'accession_number' => 'PG-TRASH-001',
        'title' => 'Verwijderbare scan',
        'created_by_user_id' => $actor->id,
    ]);

    $originalBytes = OperationsPostgresAcceptance::jpegBytes(640, 480);
    $originalKey = 'originals/'.$asset->id.'/trash.jpg';
    $derivativeKey = 'derivatives/'.$asset->id.'/preview-300.jpg';
    Storage::disk('local')->put($originalKey, $originalBytes);
    Storage::disk('local')->put($derivativeKey, OperationsPostgresAcceptance::jpegBytes(300, 200));

    AssetFile::query()->create([
        'asset_id' => $asset->id,
        'storage_disk' => 'local',
        'storage_key' => $originalKey,
        'sha256' => hash('sha256', $originalBytes),
        'media_type' => 'image/jpeg',
        'byte_size' => strlen($originalBytes),
        'original_filename' => 'trash.jpg',
        'derivatives' => ['preview300' => $derivativeKey],
        'is_primary' => true,
    ]);

    $trash = app(TrashService::class);
    $trash->moveToTrash($asset, 'Dubbel ingevoerd dossier', $actor);

    // Default predicates — count, find, where and relation loads — must all deny it.
    expect(Asset::query()->count())->toBe(0)
        ->and(Asset::query()->find($asset->id))->toBeNull()
        ->and(Asset::query()->where('accession_number', 'PG-TRASH-001')->exists())->toBeFalse()
        ->and(Asset::query()->pluck('id')->all())->toBe([])
        ->and(AssetFile::query()->whereHas('asset')->count())->toBe(0);

    // The timestamptz column really carries the deletion on PostgreSQL.
    $raw = DB::table('assets')->where('id', $asset->id)->first();
    expect($raw->deleted_at)->not->toBeNull()
        ->and($raw->deleted_by_user_id)->toBe($actor->id)
        ->and($raw->deletion_reason)->toBe('Dubbel ingevoerd dossier');

    // Trash is recoverable and the files were never touched.
    expect(Storage::disk('local')->exists($originalKey))->toBeTrue();
    $trash->restoreFromTrash(Asset::onlyTrashed()->findOrFail($asset->id), $actor);
    expect(Asset::query()->count())->toBe(1)
        ->and(Asset::query()->findOrFail($asset->id)->deleted_at)->toBeNull();

    // Purge destroys the real bytes and records the audit row.
    $trash->moveToTrash(Asset::query()->findOrFail($asset->id), 'Definitieve vernietiging', $actor);
    $trash->purgeAsset(Asset::onlyTrashed()->findOrFail($asset->id), 'Onherroepelijk verwijderd', $actor);

    expect(Storage::disk('local')->exists($originalKey))->toBeFalse()
        ->and(Storage::disk('local')->exists($derivativeKey))->toBeFalse()
        ->and(Asset::withTrashed()->find($asset->id))->toBeNull()
        ->and(AssetFile::query()->where('storage_key', $originalKey)->exists())->toBeFalse();

    $log = TrashPurgeLog::query()->where('accession_number', 'PG-TRASH-001')->firstOrFail();
    expect($log->deleted_files_count)->toBe(1)
        ->and($log->purged_by_user_id)->toBe($actor->id);
})->group('postgres')->skip($requiresPostgres, 'Requires FOTOARCHIEF_TEST_PG_OPERATIONS_DATABASE ending in _final_test.');

it('keeps the OCR job inside the ingest worker timeout and reserves it on the ingest connection', function (): void {
    $database = (string) getenv('FOTOARCHIEF_TEST_PG_OPERATIONS_DATABASE');
    OperationsPostgresAcceptance::bootDatabase($database);
    OperationsPostgresAcceptance::bootPrivateStorage();

    $workerTimeout = 120;
    $retryAfter = (int) config('queue.connections.ingest.retry_after');

    // A per-job timeout overrides the worker --timeout, so it may never exceed it,
    // and it must stay strictly below retry_after or the job is reserved twice.
    expect(ProcessAssetOcrJob::MAX_JOB_TIMEOUT_SECONDS)->toBeLessThanOrEqual($workerTimeout)
        ->and(ProcessAssetOcrJob::MAX_JOB_TIMEOUT_SECONDS)->toBeLessThan($retryAfter);

    $job = new ProcessAssetOcrJob('01HZZZZZZZZZZZZZZZZZZZZZZZ');
    expect($job->timeout)->toBe(ProcessAssetOcrJob::MAX_JOB_TIMEOUT_SECONDS)
        ->and($job->timeout)->toBeLessThanOrEqual($workerTimeout);

    // The Tesseract process must be killable before the job itself is killed.
    config(['services.tesseract.timeout' => 3600]);
    $processTimeout = app(TesseractOcrService::class)->resolveProcessTimeout();
    expect($processTimeout)->toBeLessThan($job->timeout);

    config(['services.tesseract.timeout' => 45]);
    expect(app(TesseractOcrService::class)->resolveProcessTimeout())->toBe(45);

    // Heavy work is pushed onto the dedicated ingest connection, sharing the
    // ingest pipeline's database atomicity rather than the default queue.
    $actor = OperationsPostgresAcceptance::administrator('ocr@example.test');
    $asset = Asset::query()->create([
        'accession_number' => 'PG-OCR-001',
        'title' => 'OCR-doel',
        'created_by_user_id' => $actor->id,
    ]);
    $bytes = OperationsPostgresAcceptance::jpegBytes(400, 300);
    $key = 'originals/'.$asset->id.'/ocr.jpg';
    Storage::disk('local')->put($key, $bytes);
    AssetFile::query()->create([
        'asset_id' => $asset->id,
        'storage_disk' => 'local',
        'storage_key' => $key,
        'sha256' => hash('sha256', $bytes),
        'media_type' => 'image/jpeg',
        'byte_size' => strlen($bytes),
        'original_filename' => 'ocr.jpg',
        'is_primary' => true,
    ]);

    DB::table('jobs')->delete();
    $this->actingAs($actor)
        ->from('/admin/operations/ocr')
        ->post('/admin/operations/ocr/assets/'.$asset->id.'/dispatch')
        ->assertRedirect('/admin/operations/ocr');

    $queued = DB::table('jobs')->get();
    expect($queued)->toHaveCount(1)
        ->and($queued->first()->queue)->toBe('ingest');

    $payload = json_decode($queued->first()->payload, true);
    expect($payload['displayName'])->toBe(ProcessAssetOcrJob::class)
        ->and($payload['timeout'])->toBe(ProcessAssetOcrJob::MAX_JOB_TIMEOUT_SECONDS);
})->group('postgres')->skip($requiresPostgres, 'Requires FOTOARCHIEF_TEST_PG_OPERATIONS_DATABASE ending in _final_test.');

it('never performs checksum, copy or purge work inside the request and finishes it on the ingest queue', function (): void {
    $database = (string) getenv('FOTOARCHIEF_TEST_PG_OPERATIONS_DATABASE');
    OperationsPostgresAcceptance::bootDatabase($database);
    OperationsPostgresAcceptance::bootPrivateStorage();

    $actor = OperationsPostgresAcceptance::administrator('queued-ops@example.test');
    $asset = Asset::query()->create([
        'accession_number' => 'PG-QUEUE-001',
        'title' => 'Grote scan',
        'created_by_user_id' => $actor->id,
    ]);

    // A genuinely large original: the kind of file that cannot be hashed, copied or
    // deleted inside an HTTP request without risking a timeout.
    $largeBytes = OperationsPostgresAcceptance::jpegBytes(600, 400).random_bytes(32 * 1024 * 1024);
    $largeSha = hash('sha256', $largeBytes);
    $originalKey = 'originals/'.$asset->id.'/large.jpg';
    Storage::disk('local')->put($originalKey, $largeBytes);

    $file = AssetFile::query()->create([
        'asset_id' => $asset->id,
        'storage_disk' => 'local',
        'storage_key' => $originalKey,
        'sha256' => $largeSha,
        'media_type' => 'image/jpeg',
        'byte_size' => strlen($largeBytes),
        'original_filename' => 'large.jpg',
        'is_primary' => true,
    ]);

    // 1. Integrity verification is queued, not executed.
    DB::table('jobs')->delete();
    $started = microtime(true);
    $this->actingAs($actor)->post('/admin/operations/integrity/run')
        ->assertRedirect('/admin/operations/integrity');
    $requestSeconds = microtime(true) - $started;

    $queued = DB::table('jobs')->get();
    expect($queued)->toHaveCount(1)
        ->and($queued->first()->queue)->toBe('ingest');
    $payload = json_decode((string) $queued->first()->payload, true);
    expect($payload['displayName'])->toBe(VerifyIntegrityJob::class)
        ->and($payload['timeout'])->toBe(OperationJob::MAX_JOB_TIMEOUT_SECONDS)
        ->and(OperationJob::MAX_JOB_TIMEOUT_SECONDS)->toBeLessThan((int) config('queue.connections.ingest.retry_after'));

    $run = OperationRun::query()->where('operation_type', VerifyIntegrityJob::TYPE)->latest('created_at')->firstOrFail();
    expect($run->status)->toBe(OperationRun::STATUS_QUEUED)
        ->and($run->processed_items)->toBe(0)
        // The request returned long before 32 MB could have been re-hashed.
        ->and($requestSeconds)->toBeLessThan(5.0);

    $run = OperationRunDriver::drive($run);
    expect($run->status)->toBe(OperationRun::STATUS_COMPLETED)
        ->and($run->processed_items)->toBe(1);

    // The original hashes clean; only its derivatives are still absent, and the job
    // reports that as an open issue rather than as a run failure.
    $check = DB::table('integrity_checks')->where('asset_file_id', $file->id)->latest('id')->first();
    expect($check)->not->toBeNull()
        ->and($check->status)->toBe('missing_derivative')
        ->and($check->actual_sha256 ?? $largeSha)->toBe($largeSha);

    // 2. Storage migration copies the real bytes on the queue and leaves the source.
    DB::table('jobs')->delete();
    $this->actingAs($actor)->post('/admin/operations/storage-migration/start', [
        'source_disk' => 'local',
        'target_disk' => 'archive',
    ])->assertRedirect('/admin/operations/storage-migration');

    expect(Storage::disk('archive')->exists($originalKey))->toBeFalse();
    $copyRun = OperationRunDriver::driveLatest(StorageCopyJob::TYPE);
    expect($copyRun->status)->toBe(OperationRun::STATUS_COMPLETED)
        ->and(hash('sha256', (string) Storage::disk('archive')->get($originalKey)))->toBe($largeSha)
        ->and(Storage::disk('local')->exists($originalKey))->toBeTrue();

    $migration = StorageMigration::query()->latest('id')->firstOrFail();
    expect($migration->status)->toBe('verified')
        ->and($migration->verified_files)->toBe(1)
        ->and($migration->failed_files)->toBe(0);

    // The immutability trigger still guards the row the queue just verified.
    expect(fn () => DB::transaction(fn () => DB::table('asset_files')
        ->where('id', $file->id)
        ->update(['storage_key' => 'originals/tampered.jpg'])))->toThrow(QueryException::class);

    // 3. Purge destroys bytes only from the queued job, never from the request.
    app(TrashService::class)->moveToTrash(Asset::query()->findOrFail($asset->id), 'Te vernietigen', $actor);
    $this->actingAs($actor)->delete('/admin/operations/trash/assets/'.$asset->id.'/purge', [
        'reason' => 'Onherroepelijke vernietiging na controle',
        'confirm_purge' => '1',
    ])->assertRedirect('/admin/operations/trash');

    expect(Storage::disk('local')->exists($originalKey))->toBeTrue()
        ->and(Asset::withTrashed()->find($asset->id))->not->toBeNull();

    $purgeRun = OperationRunDriver::driveLatest(PurgeAssetsJob::TYPE);
    expect($purgeRun->status)->toBe(OperationRun::STATUS_COMPLETED)
        ->and(Storage::disk('local')->exists($originalKey))->toBeFalse()
        ->and(Asset::withTrashed()->find($asset->id))->toBeNull();

    $log = TrashPurgeLog::query()->where('accession_number', 'PG-QUEUE-001')->firstOrFail();
    expect($log->purged_by_user_id)->toBe($actor->id);
})->group('postgres')->skip($requiresPostgres, 'Requires FOTOARCHIEF_TEST_PG_OPERATIONS_DATABASE ending in _final_test.');
