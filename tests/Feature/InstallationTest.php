<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Installation\InstallationBootstrap;
use App\Modules\Installation\InstallationDatabase;
use App\Modules\Installation\InstallationSettings;
use App\Modules\Installation\InstallationState;
use App\Modules\Installation\InstallationStorage;
use App\Modules\Installation\InstallationStore;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class SqliteInstallationDatabase extends InstallationDatabase
{
    public function connect(InstallationSettings $settings): void
    {
        config(['database.default' => 'sqlite']);
        DB::connection()->select('SELECT 1');
    }
}

function installationInput(): array
{
    return [
        'db_host' => 'postgres', 'db_port' => '5432', 'db_database' => 'fotoarchief',
        'db_username' => 'fotoarchief', 'db_password' => 'disposable-db-password',
        'db_sslmode' => 'prefer', 'disk' => 'local', 'path_style' => '1',
        'name' => 'Archive owner', 'email' => 'owner@example.test',
        'password' => 'a-long-test-password', 'password_confirmation' => 'a-long-test-password',
    ];
}

beforeEach(function (): void {
    $this->installationDirectory = sys_get_temp_dir().'/fotoarchief-test-'.bin2hex(random_bytes(8));
    config([
        'installation.enabled' => true,
        'installation.path' => $this->installationDirectory.'/installation',
        'filesystems.disks.local.root' => $this->installationDirectory.'/objects',
        'cache.default' => 'array',
    ]);
    app()->forgetInstance(InstallationStore::class);
    $this->store = app(InstallationStore::class);
    $this->store->initialize();
    app()->bind(InstallationDatabase::class, SqliteInstallationDatabase::class);
    DB::purge('sqlite');
    config(['database.default' => 'sqlite']);
    RateLimiter::clear('installation-unlock');
    RateLimiter::clear('installation-unlock:127.0.0.1');
    RateLimiter::clear('login:127.0.0.1');
});

afterEach(function (): void {
    DB::disconnect('sqlite');
    RefreshDatabaseState::$migrated = false;
    File::deleteDirectory($this->installationDirectory);
});

it('redirects fresh visits to setup and keeps API unavailable while liveness works', function (): void {
    $this->get('/')->assertRedirect('/setup');
    $this->get('/setup')->assertOk()
        ->assertSee('Installatiecode')
        ->assertSee('pdo_pgsql')
        ->assertSee('geen AI-service')
        ->assertDontSee('disposable-db-password');
    $this->getJson('/api/status')->assertStatus(503);
    $this->get('/up')->assertOk();
    $this->post('/setup/complete', installationInput())->assertForbidden();
});

it('unlocks with a private code and throttles invalid attempts without flashing it', function (): void {
    $this->from('/setup')->post('/setup/unlock', ['code' => 'wrong-secret'])->assertSessionHasErrors('code');
    expect(session()->getOldInput('code'))->toBeNull();
    $code = trim(file_get_contents($this->store->directory.'/setup-code.txt'));
    $this->post('/setup/unlock', ['code' => $code])->assertRedirect('/setup');
    $this->get('/setup')->assertSee('Controleren en installeren')->assertDontSee($code);
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->post('/setup/unlock', ['code' => 'wrong'])->assertSessionHasErrors('code');
    }
    $this->post('/setup/unlock', ['code' => 'wrong'])->assertStatus(429);
});

it('expires installation authorization before connection probes', function (): void {
    $this->withSession(['installation_authorized_until' => now()->subMinute()->timestamp])
        ->post('/setup/check', installationInput())->assertForbidden();
});

it('tests connections without installing or persisting credentials', function (): void {
    $this->withSession(['installation_authorized_until' => now()->addMinutes(20)->timestamp])
        ->post('/setup/check', installationInput())->assertRedirect('/setup')->assertSessionHas('status');
    expect($this->store->read()->phase)->toBe('pending')
        ->and($this->store->read()->settings)->toBeNull()
        ->and(session()->getOldInput('db_password'))->toBeNull()
        ->and(session()->getOldInput('password'))->toBeNull()
        ->and(DB::getSchemaBuilder()->getTables())->toBe([]);
    expect(File::allFiles($this->installationDirectory.'/objects'))->toHaveCount(0);
});

it('installs schema and administrator then locks setup and allows login and logout', function (): void {
    $this->withSession(['installation_authorized_until' => now()->addMinutes(20)->timestamp])
        ->post('/setup/complete', installationInput())->assertRedirect('/login');
    expect($this->store->completed())->toBeTrue()
        ->and(User::query()->count())->toBe(1)
        ->and(Hash::check('a-long-test-password', User::query()->firstOrFail()->password))->toBeTrue()
        ->and(User::query()->firstOrFail()->hasPermission('users.manage'))->toBeTrue()
        ->and(DB::table('installation_receipts')->count())->toBe(1);
    $this->get('/setup')->assertNotFound();
    $this->post('/setup/complete', installationInput())->assertNotFound();
    $this->get('/admin')->assertRedirect('/login');
    $this->post('/login', ['email' => 'owner@example.test', 'password' => 'a-long-test-password'])->assertRedirect('/admin');
    $this->get('/admin')->assertOk()->assertSee('Archive owner')->assertSee('Wizard vergrendeld')->assertDontSee('disposable-db-password');
    $this->post('/logout')->assertRedirect('/login');
    $this->get('/admin')->assertRedirect('/login');
});

it('resumes after database commit without recreating or resetting the administrator', function (): void {
    $this->withSession(['installation_authorized_until' => now()->addMinutes(20)->timestamp])
        ->post('/setup/complete', installationInput())->assertRedirect('/login');
    $state = $this->store->read();
    $state->phase = 'installing';
    $this->store->save($state);
    $input = installationInput();
    $input['password'] = $input['password_confirmation'] = 'a-different-test-password';
    $this->withSession(['installation_authorized_until' => now()->addMinutes(20)->timestamp])
        ->post('/setup/complete', $input)->assertRedirect('/login');
    expect(User::query()->count())->toBe(1)
        ->and(Hash::check('a-long-test-password', User::query()->firstOrFail()->password))->toBeTrue()
        ->and($this->store->completed())->toBeTrue();
});

it('rejects switching database during resume and never flashes connection secrets', function (): void {
    $this->withSession(['installation_authorized_until' => now()->addMinutes(20)->timestamp])
        ->post('/setup/complete', installationInput())->assertRedirect('/login');
    $state = $this->store->read();
    $state->phase = 'installing';
    $this->store->save($state);
    $input = installationInput();
    $input['db_database'] = 'another_database';
    $this->withSession(['installation_authorized_until' => now()->addMinutes(20)->timestamp])
        ->from('/setup')->post('/setup/complete', $input)->assertSessionHasErrors('installation');
    expect($this->store->completed())->toBeFalse()
        ->and(session()->getOldInput('db_password'))->toBeNull();
});

it('reports a storage failure without exposing credentials or starting migrations', function (): void {
    app()->instance(InstallationStorage::class, new class extends InstallationStorage
    {
        public function check(InstallationSettings $settings): void
        {
            throw new RuntimeException('sensitive-secret-must-not-leak');
        }
    });
    $this->withSession(['installation_authorized_until' => now()->addMinutes(20)->timestamp])
        ->from('/setup')->post('/setup/complete', installationInput())->assertSessionHasErrors('installation');
    $this->get('/setup')->assertDontSee('sensitive-secret-must-not-leak')->assertSee('Installatiecontrole mislukt');
    expect($this->store->read()->phase)->toBe('pending')
        ->and(DB::getSchemaBuilder()->getTables())->toBe([]);
});

it('refuses an existing database rather than claiming an existing account', function (): void {
    DB::statement('CREATE TABLE existing_archive (id INTEGER)');
    $this->withSession(['installation_authorized_until' => now()->addMinutes(20)->timestamp])
        ->from('/setup')->post('/setup/complete', installationInput())->assertSessionHasErrors('installation');
    expect($this->store->read()->phase)->toBe('pending');
});

it('preserves the key across initialization and reloads saved config over environment defaults', function (): void {
    $original = $this->store->initialize();
    expect($this->store->initialize()->key)->toBe($original->key);
    $original->settings = new InstallationSettings('stored-host', 5432, 'stored-db', 'stored-user', 'private-test', 'require', 'local', '', '', '', '', '', false);
    $original->phase = 'complete';
    $original->fingerprint = $original->settings->fingerprint('owner@example.test');
    $this->store->save($original);
    config(['app.key' => 'wrong-environment-key', 'database.connections.pgsql.host' => 'wrong-host']);
    InstallationBootstrap::boot($this->app);
    expect(config('app.key'))->toBe($original->key)
        ->and(config('database.default'))->toBe('pgsql')
        ->and(config('database.connections.pgsql.host'))->toBe('stored-host')
        ->and(config('filesystems.default'))->toBe('local');
    config(['database.default' => 'sqlite']);
});

it('fails closed on corrupt state and rejects concurrent installation attempts', function (): void {
    expect(fn () => InstallationState::decode('{"phase":"complete"}'))->toThrow(RuntimeException::class);
    $handle = fopen($this->store->directory.'/installation.lock', 'c');
    flock($handle, LOCK_EX);
    try {
        expect(fn () => $this->store->initialize())->toThrow(RuntimeException::class);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
});

it('does not regenerate a key if only the state file has been lost', function (): void {
    unlink($this->store->directory.'/state.json');
    expect(fn () => $this->store->initialize())->toThrow(RuntimeException::class);
});

it('blocks configuration caching until installation is complete', function (): void {
    $event = new CommandStarting('config:cache', new ArrayInput([]), new NullOutput);
    expect(fn () => Event::dispatch($event))->toThrow(RuntimeException::class);
    $this->withSession(['installation_authorized_until' => now()->addMinutes(20)->timestamp])
        ->post('/setup/complete', installationInput())->assertRedirect('/login');
    expect(fn () => Event::dispatch($event))->not->toThrow(RuntimeException::class);
});

it('requires S3 fields and keeps secrets out of validation redirects', function (): void {
    $input = installationInput();
    $input['disk'] = 's3';
    $input['secret_key'] = 'test-secret-do-not-flash';
    $this->withSession(['installation_authorized_until' => now()->addMinutes(20)->timestamp])
        ->from('/setup')->post('/setup/complete', $input)->assertSessionHasErrors(['endpoint', 'bucket', 'access_key']);
    expect(session()->getOldInput('secret_key'))->toBeNull();
});

it('throttles failed administrator logins without authenticating', function (): void {
    $this->withSession(['installation_authorized_until' => now()->addMinutes(20)->timestamp])
        ->post('/setup/complete', installationInput())->assertRedirect('/login');
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->from('/login')->post('/login', ['email' => 'owner@example.test', 'password' => 'incorrect'])->assertSessionHasErrors('email');
    }
    $this->post('/login', ['email' => 'owner@example.test', 'password' => 'a-long-test-password'])->assertStatus(429);
    $this->assertGuest();
});

it('exposes pending and completed readiness to workers without leaking the code', function (): void {
    expect(Artisan::call('installation:ready'))->toBe(1)
        ->and(Artisan::call('installation:prepare', ['--quiet-code' => true]))->toBe(0)
        ->and(Artisan::output())->toBe('');
    $this->withSession(['installation_authorized_until' => now()->addMinutes(20)->timestamp])
        ->post('/setup/complete', installationInput())->assertRedirect('/login');
    expect(Artisan::call('installation:ready'))->toBe(0)
        ->and(Artisan::call('installation:prepare'))->toBe(0)
        ->and(Artisan::output())->toContain('Installatie is al voltooid.');
});
