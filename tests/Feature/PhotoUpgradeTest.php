<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Ingest\Jobs\ProcessUpload;
use App\Modules\Ingest\Models\QuarantineUpload;
use App\Modules\Ingest\Services\ImageProcessor;
use App\Modules\Ingest\Services\QuarantineUploadService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('upgrades the previous schema preserving originals then processes and edits on PostgreSQL', function (): void {
    $database = (string) getenv('FOTOARCHIEF_TEST_PG_UPGRADE_DATABASE');
    expect($database)->toEndWith('_workflow_test');
    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql.host' => getenv('FOTOARCHIEF_TEST_PG_HOST') ?: '127.0.0.1',
        'database.connections.pgsql.port' => getenv('FOTOARCHIEF_TEST_PG_PORT') ?: 5432,
        'database.connections.pgsql.database' => $database,
        'database.connections.pgsql.username' => getenv('FOTOARCHIEF_TEST_PG_USER'),
        'database.connections.pgsql.password' => getenv('FOTOARCHIEF_TEST_PG_PASSWORD'),
        'database.connections.pgsql.sslmode' => 'prefer',
        'filesystems.default' => 'local',
    ]);
    DB::purge('pgsql');
    Storage::fake('local');
    try {
        expect(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"))->toBe([]);
        $oldMigrations = array_slice(glob(database_path('migrations/*.php')), 0, 4);
        expect($oldMigrations)->toHaveCount(4);
        Artisan::call('migrate', ['--path' => $oldMigrations, '--realpath' => true, '--force' => true]);
        expect(DB::table('migrations')->count())->toBe(4);
        $oldAsset = Asset::query()->create(['accession_number' => 'UPGRADE-EXISTING', 'title' => 'Preserved']);
        $oldFile = AssetFile::query()->create(['asset_id' => $oldAsset->id, 'storage_key' => 'legacy/immutable', 'sha256' => str_repeat('c', 64), 'media_type' => 'image/jpeg', 'byte_size' => 45]);
        Artisan::call('migrate', ['--force' => true]);
        expect(DB::table('migrations')->count())->toBe(count(glob(database_path('migrations/*.php'))))
            ->and($oldFile->fresh()->sha256)->toBe(str_repeat('c', 64))
            ->and($oldFile->fresh()->storage_key)->toBe('legacy/immutable')
            ->and($oldFile->fresh()->storage_disk)->toBeNull()
            ->and($oldAsset->fresh()->title)->toBe('Preserved');
        $this->seed(DatabaseSeeder::class);
        $user = User::query()->create(['name' => 'Upgrade tester', 'email' => 'upgrade@example.test', 'password' => 'disposable-upgrade-password']);
        $user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
        $asset = Asset::query()->create(['accession_number' => 'UPGRADE-NEW', 'title' => 'New', 'created_by_user_id' => $user->id]);
        $upload = app(QuarantineUploadService::class)->quarantine($asset, UploadedFile::fake()->image('pg.png', 120, 60), $user->id);
        Artisan::call('queue:work', ['connection' => 'ingest', '--once' => true, '--tries' => 3]);
        expect($upload->fresh()->status)->toBe('completed')->and(AssetFile::count())->toBe(2);
        $path = '/admin/assets/'.$asset->id;
        $this->actingAs($user)->put($path, [
            'lock_version' => 1, 'title' => 'Updated on PostgreSQL', 'rights_status' => 'unverified',
            'date_precision' => 'year', 'date_earliest' => '1901-03-15',
        ])->assertSessionHasNoErrors()->assertRedirect();
        expect($asset->fresh()->date_precision)->toBe('year')->and($asset->fresh()->lock_version)->toBe(2);
        $this->get($path)->assertOk()->assertSee('1901-01-01');
        // A second migration run cannot reset metadata or create extra revisions.
        Artisan::call('migrate', ['--force' => true]);
        expect($asset->fresh()->lock_version)->toBe(2);
        $file = $asset->files()->sole();
        expect(fn () => DB::transaction(fn () => DB::table('asset_files')->where('id', $file->id)->update(['sha256' => str_repeat('d', 64)])))->toThrow(QueryException::class);
        // Duplicate SHA protection and permanent rejection also hold on PostgreSQL.
        $duplicate = app(QuarantineUploadService::class)->quarantine($asset, UploadedFile::fake()->image('pg.png', 120, 60), $user->id);
        (new ProcessUpload($duplicate->id))->handle(app(ImageProcessor::class));
        expect(QuarantineUpload::findOrFail($duplicate->id)->status)->toBe('rejected');
    } finally {
        DB::disconnect('pgsql');
    }
})->group('postgres')->skip(fn (): bool => getenv('FOTOARCHIEF_TEST_PG_UPGRADE_DATABASE') === false,
    'Requires a separate empty PostgreSQL database ending in _workflow_test.');
