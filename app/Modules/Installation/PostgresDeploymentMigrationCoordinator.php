<?php

declare(strict_types=1);

namespace App\Modules\Installation;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PostgresDeploymentMigrationCoordinator implements DeploymentMigrationCoordinator
{
    private const LOCK_NAMESPACE = 760076;

    private const LOCK_KEY = 20260916;

    public function __construct(private readonly Migrator $migrator) {}

    public function migrate(): void
    {
        $connection = $this->postgresConnection();

        if (! $this->tryAcquireLock($connection)) {
            $this->waitForCoordinator($connection);
            $this->ensureNoPendingMigrations();

            return;
        }

        try {
            $exitCode = Artisan::call('migrate', ['--force' => true]);
            if ($exitCode !== 0) {
                throw new RuntimeException("Laravel migrate failed with exit code {$exitCode}.");
            }

            $this->ensureNoPendingMigrations();
        } finally {
            $this->releaseLock($connection);
        }
    }

    public function status(): DeploymentMigrationStatus
    {
        $connection = $this->postgresConnection();
        if (! $this->tryAcquireLock($connection)) {
            return new DeploymentMigrationStatus(true, null);
        }

        try {
            return new DeploymentMigrationStatus(false, $this->pendingMigrations());
        } finally {
            $this->releaseLock($connection);
        }
    }

    private function postgresConnection(): Connection
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Automatic deployment migrations require the configured PostgreSQL connection.');
        }

        return $connection;
    }

    private function tryAcquireLock(Connection $connection): bool
    {
        $rows = $connection->select('SELECT pg_try_advisory_lock(?, ?) AS acquired', [self::LOCK_NAMESPACE, self::LOCK_KEY]);
        $first = (array) ($rows[0] ?? []);
        $acquired = $first['acquired'] ?? false;

        return $acquired === true || $acquired === 1 || $acquired === '1' || $acquired === 't' || $acquired === 'true';
    }

    private function waitForCoordinator(Connection $connection): void
    {
        $timeout = (int) config('installation.deployment_migration_lock_timeout_seconds', 300);
        if ($timeout < 1) {
            throw new RuntimeException('Deployment migration lock timeout must be at least one second.');
        }

        $connection->select("SELECT set_config('lock_timeout', ?, false)", [$timeout.'s']);
        $acquired = false;

        try {
            $connection->select('SELECT pg_advisory_lock(?, ?)', [self::LOCK_NAMESPACE, self::LOCK_KEY]);
            $acquired = true;
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
            if ($sqlState !== '55P03') {
                throw $exception;
            }

            throw new RuntimeException("Timed out after {$timeout} seconds while waiting for deployment migrations.", 0, $exception);
        } finally {
            try {
                if ($acquired) {
                    $this->releaseLock($connection);
                }
            } finally {
                $connection->select("SELECT set_config('lock_timeout', '0', false)");
            }
        }
    }

    private function releaseLock(Connection $connection): void
    {
        $connection->select('SELECT pg_advisory_unlock(?, ?)', [self::LOCK_NAMESPACE, self::LOCK_KEY]);
    }

    private function ensureNoPendingMigrations(): void
    {
        $pending = $this->pendingMigrations();
        if ($pending !== []) {
            throw new RuntimeException('Database migrations are still pending after the coordinated migration run: '.implode(', ', $pending));
        }
    }

    /**
     * @return list<string>
     */
    private function pendingMigrations(): array
    {
        if (! $this->migrator->repositoryExists()) {
            throw new RuntimeException('Migration repository was not created.');
        }

        $files = $this->migrator->getMigrationFiles([database_path('migrations')]);
        $ran = $this->migrator->getRepository()->getRan();

        return array_values(array_diff(array_keys($files), $ran));
    }
}
