<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Modules\ArchiveOperations\Models\BackupRecord;
use App\Modules\ArchiveOperations\Models\RestoreDrill;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class RestoreDrillService
{
    public function __construct(private readonly BackupRegisterService $backups) {}

    public function run(BackupRecord $backup, string $database, string $directory, bool $confirmed): RestoreDrill
    {
        if (! $confirmed || ! preg_match('/^[a-z][a-z0-9_]*_restore_drill$/D', $database)
            || DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() === $database) {
            throw new RuntimeException(__('recovery.errors.confirm_target'));
        }
        $parent = realpath(dirname($directory));
        if ($parent === false || ! preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $directory)
            || in_array(basename($directory), ['', '.', '..'], true) || file_exists($directory) || is_link($directory)) {
            throw new RuntimeException(__('recovery.errors.target_directory'));
        }
        $target = $parent.DIRECTORY_SEPARATOR.basename($directory);
        foreach ([base_path(), storage_path(), $backup->location] as $protected) {
            $protected = realpath($protected);
            if ($protected !== false && str_starts_with(strtolower($target).DIRECTORY_SEPARATOR, strtolower($protected).DIRECTORY_SEPARATOR)) {
                throw new RuntimeException(__('recovery.errors.target_protected'));
            }
        }
        $drill = RestoreDrill::query()->create([
            'backup_record_id' => $backup->id, 'target_database' => $database, 'target_directory' => $target, 'status' => 'running',
        ]);
        $connectionName = 'restore_drill_'.$drill->getKey();
        try {
            $verified = $this->backups->inspect($backup->location);
            if ($verified['manifest_sha256'] !== $backup->manifest_sha256 || $verified['version'] !== trim((string) file_get_contents(base_path('VERSION')))) {
                throw new RuntimeException(__('recovery.errors.version_changed'));
            }
            $config = DB::connection()->getConfig();
            $connection = DB::connectUsing($connectionName, [...$config, 'url' => null, 'database' => $database], true);
            if ($this->tableCount($connection) !== 0 || $this->hasOtherObjects($connection)) {
                throw new RuntimeException(__('recovery.errors.database_occupied'));
            }
            if (! mkdir($target, 0700) || ! mkdir($target.'/storage', 0700)) {
                throw new RuntimeException(__('recovery.errors.create_target'));
            }
            $input = fopen($backup->location.'/storage-app.tar', 'rb');
            if ($input === false) {
                throw new RuntimeException(__('recovery.errors.open_archive'));
            }
            try {
                $extract = new Process([PHP_BINARY, base_path('scripts/restore-storage.php')], $target, null, $input, 3600);
                $extract->run();
                if (! $extract->isSuccessful()) {
                    throw new RuntimeException(__('recovery.errors.extraction', ['code' => $extract->getExitCode()]));
                }
            } finally {
                fclose($input);
            }
            $restore = new Process(['pg_restore', '--single-transaction', '--exit-on-error', '--no-owner', '--no-acl', '--dbname='.$database, $backup->location.'/database.dump'], $target, [
                'PGHOST' => (string) ($config['host'] ?? ''), 'PGPORT' => (string) ($config['port'] ?? '5432'),
                'PGUSER' => (string) ($config['username'] ?? ''), 'PGPASSWORD' => (string) ($config['password'] ?? ''),
                'PGSSLMODE' => (string) ($config['sslmode'] ?? 'prefer'),
            ], null, 3600);
            $restore->run();
            if (! $restore->isSuccessful()) {
                throw new RuntimeException(__('recovery.errors.database_restore', ['code' => $restore->getExitCode()]));
            }
            $count = 0;
            foreach ($connection->table('asset_files')->orderBy('id')->cursor() as $file) {
                if ($file->storage_disk !== 'local' || ! is_string($file->storage_key) || $file->storage_key === ''
                    || str_contains($file->storage_key, '..') || str_contains($file->storage_key, '\\') || str_starts_with($file->storage_key, '/')) {
                    throw new RuntimeException(__('recovery.errors.storage_location'));
                }
                $path = $target.'/storage/app/private/'.$file->storage_key;
                if (! is_file($path) || is_link($path) || hash_file('sha256', $path) !== $file->sha256 || filesize($path) !== (int) $file->byte_size) {
                    throw new RuntimeException(__('recovery.errors.original_mismatch', ['id' => $file->id]));
                }
                $count++;
            }
            if ($this->backups->inspect($backup->location)['manifest_sha256'] !== $backup->manifest_sha256) {
                throw new RuntimeException(__('recovery.errors.backup_changed'));
            }
            $drill->update(['status' => 'verified', 'finished_at' => now(), 'report' => [
                'version' => $verified['version'], 'manifest_sha256' => $verified['manifest_sha256'],
                'database_tables' => $this->tableCount($connection), 'verified_originals' => $count,
                'scope' => 'database-and-local-originals', 'application_login_verified' => false,
            ]]);
        } catch (Throwable $exception) {
            $message = $exception::class === RuntimeException::class
                ? $exception->getMessage() : __('recovery.errors.drill_failed', ['class' => $exception::class]);
            $drill->update(['status' => 'failed', 'error' => $message, 'finished_at' => now()]);
            Log::error(__('recovery.drill_failed'), ['drill_id' => $drill->getKey(), 'exception_class' => $exception::class]);
            throw new RuntimeException($message, previous: $exception);
        } finally {
            DB::purge($connectionName);
        }

        return $drill;
    }

    private function tableCount(ConnectionInterface $connection): int
    {
        return (int) $connection->table('information_schema.tables')->whereNotIn('table_schema', ['pg_catalog', 'information_schema'])->count();
    }

    private function hasOtherObjects(ConnectionInterface $connection): bool
    {
        foreach (['pg_class' => 'relnamespace', 'pg_proc' => 'pronamespace', 'pg_type' => 'typnamespace'] as $catalogue => $namespace) {
            if ($connection->table('pg_catalog.'.$catalogue.' as object')
                ->join('pg_catalog.pg_namespace as namespace', 'namespace.oid', '=', 'object.'.$namespace)
                ->where('namespace.nspname', 'not like', 'pg_%')
                ->where('namespace.nspname', '!=', 'information_schema')->exists()) {
                return true;
            }
        }

        return false;
    }
}
