<?php

declare(strict_types=1);

use App\Modules\Installation\DeploymentMigrationCoordinator;
use App\Modules\Installation\DeploymentMigrationStatus;
use App\Modules\Installation\InstallationSettings;
use App\Modules\Installation\InstallationState;
use App\Modules\Installation\InstallationStore;
use App\Modules\Installation\PostgresDeploymentMigrationCoordinator;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

final class FakeDeploymentMigrationCoordinator implements DeploymentMigrationCoordinator
{
    public int $runs = 0;

    public ?Throwable $failure = null;

    public DeploymentMigrationStatus $currentStatus;

    public function __construct()
    {
        $this->currentStatus = new DeploymentMigrationStatus(false, []);
    }

    public function migrate(): void
    {
        $this->runs++;

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    public function status(): DeploymentMigrationStatus
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->currentStatus;
    }
}

beforeEach(function (): void {
    $this->installationDirectory = sys_get_temp_dir().'/fotoarchief-migration-test-'.bin2hex(random_bytes(8));
    config([
        'installation.enabled' => true,
        'installation.path' => $this->installationDirectory.'/installation',
    ]);
    app()->forgetInstance(InstallationStore::class);
    $this->store = app(InstallationStore::class);
    $this->store->initialize();
    $this->coordinator = new FakeDeploymentMigrationCoordinator;
    app()->instance(DeploymentMigrationCoordinator::class, $this->coordinator);
});

afterEach(function (): void {
    File::deleteDirectory($this->installationDirectory);
});

function markInstallationComplete(InstallationStore $store): void
{
    $settings = new InstallationSettings('postgres', 5432, 'fotoarchief', 'fotoarchief', 'private-db-password', 'prefer', 'local', '', '', '', '', '', true);
    $state = new InstallationState(
        id: 'deployment-migration-test',
        key: 'base64:'.base64_encode(str_repeat('a', 32)),
        codeHash: hash('sha256', 'private-installation-code'),
        phase: 'complete',
        settings: $settings,
        fingerprint: $settings->fingerprint('owner@example.test'),
    );
    $store->save($state);
}

it('keeps database-free onboarding available by skipping migrations before installation is complete', function (): void {
    expect(Artisan::call('installation:migrate-ready'))->toBe(0)
        ->and($this->coordinator->runs)->toBe(0)
        ->and(Artisan::output())->toContain('automatische databasemigratie wordt overgeslagen');
});

it('coordinates migrations after installation is complete before runtime services start', function (): void {
    markInstallationComplete($this->store);

    expect(Artisan::call('installation:migrate-ready'))->toBe(0)
        ->and($this->coordinator->runs)->toBe(1)
        ->and(Artisan::output())->toContain('Databaseschema is klaar');
});

it('fails closed and does not print secret-bearing exception messages when migration coordination fails', function (): void {
    markInstallationComplete($this->store);
    $this->coordinator->failure = new RuntimeException('sensitive-detail-that-must-not-be-printed');

    $exitCode = Artisan::call('installation:migrate-ready');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($this->coordinator->runs)->toBe(1)
        ->and($output)->toContain('Automatische databasemigratie is mislukt')
        ->and($output)->toContain(RuntimeException::class)
        ->and($output)->not->toContain('sensitive-detail-that-must-not-be-printed');
});

it('reports that migration status is not applicable before onboarding completes', function (): void {
    expect(Artisan::call('installation:migration-status'))->toBe(0)
        ->and(Artisan::output())->toContain('nog niet van toepassing');
});

it('reports active and pending migration states as not ready', function (): void {
    markInstallationComplete($this->store);
    $this->coordinator->currentStatus = new DeploymentMigrationStatus(true, null);

    $activeExitCode = Artisan::call('installation:migration-status');
    $activeOutput = Artisan::output();

    expect($activeExitCode)->toBe(1)
        ->and($activeOutput)->toContain('ander proces');

    $this->coordinator->currentStatus = new DeploymentMigrationStatus(false, ['2026_10_01_000000_example']);

    $pendingExitCode = Artisan::call('installation:migration-status');
    $pendingOutput = Artisan::output();

    expect($pendingExitCode)->toBe(1)
        ->and($pendingOutput)->toContain('Achterstallige databasemigraties: 1')
        ->and($pendingOutput)->toContain('2026_10_01_000000_example');
});

it('reports a ready schema and hides status exception details', function (): void {
    markInstallationComplete($this->store);

    expect(Artisan::call('installation:migration-status'))->toBe(0)
        ->and(Artisan::output())->toContain('Databaseschema is actueel');

    $this->coordinator->failure = new RuntimeException('private-database-detail');
    $exitCode = Artisan::call('installation:migration-status');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain(RuntimeException::class)
        ->and($output)->not->toContain('private-database-detail');
});

it('uses a bounded deployment migration lock wait by default', function (): void {
    expect(config('installation.deployment_migration_lock_timeout_seconds'))->toBe(300);
});

it('fails closed on a PostgreSQL migration lock timeout and resets the session timeout', function (): void {
    config(['installation.deployment_migration_lock_timeout_seconds' => 7]);

    $driverException = new PDOException('canceling statement due to lock timeout');
    $driverException->errorInfo = ['55P03', 7, 'canceling statement due to lock timeout'];
    $queryException = new QueryException(
        'pgsql',
        'SELECT pg_advisory_lock(?, ?)',
        [760076, 20260916],
        $driverException,
    );

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
    $connection->shouldReceive('select')
        ->once()
        ->with('SELECT pg_try_advisory_lock(?, ?) AS acquired', [760076, 20260916])
        ->andReturn([(object) ['acquired' => false]]);
    $connection->shouldReceive('select')
        ->once()
        ->with("SELECT set_config('lock_timeout', ?, false)", ['7s'])
        ->andReturn([]);
    $connection->shouldReceive('select')
        ->once()
        ->with('SELECT pg_advisory_lock(?, ?)', [760076, 20260916])
        ->andThrow($queryException);
    $connection->shouldReceive('select')
        ->once()
        ->with("SELECT set_config('lock_timeout', '0', false)")
        ->andReturn([]);
    $connection->shouldNotReceive('select')
        ->with('SELECT pg_advisory_unlock(?, ?)', Mockery::any());

    DB::shouldReceive('connection')->once()->andReturn($connection);

    $coordinator = new PostgresDeploymentMigrationCoordinator(Mockery::mock(Migrator::class));

    expect(fn () => $coordinator->migrate())
        ->toThrow(RuntimeException::class, 'Timed out after 7 seconds while waiting for deployment migrations.');
});
