<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Installation\InstallationDatabase;
use App\Modules\Installation\InstallationSettings;
use App\Modules\Installation\InstallationStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

it('installs against an empty PostgreSQL database and enforces its native constraints', function (): void {
    $database = (string) getenv('FOTOARCHIEF_TEST_PG_DATABASE');
    expect($database)->toEndWith('_onboarding_test');
    $directory = sys_get_temp_dir().'/fotoarchief-pg-test-'.bin2hex(random_bytes(8));
    $store = new InstallationStore($directory);
    app()->instance(InstallationStore::class, $store);
    config([
        'installation.enabled' => true,
        'installation.path' => $directory,
        'filesystems.disks.local.root' => $directory.'/private',
    ]);
    $settings = new InstallationSettings(
        host: (string) (getenv('FOTOARCHIEF_TEST_PG_HOST') ?: '127.0.0.1'),
        port: (int) (getenv('FOTOARCHIEF_TEST_PG_PORT') ?: 5432),
        database: $database,
        username: (string) getenv('FOTOARCHIEF_TEST_PG_USER'),
        password: (string) getenv('FOTOARCHIEF_TEST_PG_PASSWORD'),
        sslmode: 'prefer', disk: 'local', endpoint: '', region: '', bucket: '',
        accessKey: '', secretKey: '', pathStyle: true,
    );

    try {
        app(InstallationDatabase::class)->connect($settings);
        app(InstallationDatabase::class)->requireEmptyDatabase();
        $store->initialize();
        $this->post('/setup/unlock', ['code' => trim(File::get($directory.'/setup-code.txt'))])
            ->assertRedirect('/setup');
        $this->post('/setup/complete', [
            'db_host' => $settings->host, 'db_port' => $settings->port,
            'db_database' => $settings->database, 'db_username' => $settings->username,
            'db_password' => $settings->password, 'db_sslmode' => 'prefer',
            'disk' => 'local', 'path_style' => '1',
            'name' => 'PostgreSQL Test Owner', 'email' => 'postgres@example.test',
            'password' => 'disposable-postgres-test-password',
            'password_confirmation' => 'disposable-postgres-test-password',
        ])->assertSessionHasNoErrors()->assertRedirect('/login');
        expect($store->completed())->toBeTrue();
        expect(DB::table('installation_receipts')->count())->toBe(1);
        expect(DB::table('migrations')->count())->toBe(5);
        $user = User::query()->sole();
        expect($user->hasPermission('users.manage'))->toBeTrue();
        expect(Hash::check('disposable-postgres-test-password', $user->password))->toBeTrue();
        $this->get('/setup')->assertNotFound();
        $this->post('/login', [
            'email' => 'postgres@example.test', 'password' => 'disposable-postgres-test-password',
        ])->assertRedirect('/admin');
        $this->get('/admin')->assertOk()->assertSee('PostgreSQL Test Owner');

        $asset = Asset::query()->create(['accession_number' => 'PG-ONBOARDING-001']);
        $file = AssetFile::query()->create([
            'asset_id' => $asset->id, 'storage_key' => 'original/pg-test',
            'sha256' => str_repeat('a', 64), 'media_type' => 'image/jpeg', 'byte_size' => 1,
        ]);
        expect(fn () => DB::transaction(fn () => DB::table('asset_files')
            ->where('id', $file->id)->update(['storage_key' => 'modified'])))
            ->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('asset_files')
            ->where('id', $file->id)->update(['sha256' => str_repeat('b', 64)])))
            ->toThrow(QueryException::class);
        expect($file->fresh()->storage_key)->toBe('original/pg-test');
    } finally {
        DB::disconnect('pgsql');
        File::deleteDirectory($directory);
    }
})->group('postgres')->skip(fn (): bool => getenv('FOTOARCHIEF_TEST_PG_DATABASE') === false,
    'Requires an explicitly supplied, empty PostgreSQL onboarding test database.');
