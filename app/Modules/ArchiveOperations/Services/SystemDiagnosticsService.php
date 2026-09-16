<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use App\Modules\Ingest\Models\QuarantineUpload;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SystemDiagnosticsService
{
    public function __construct(
        private readonly SystemHeartbeatService $heartbeats,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getAllDiagnostics(): array
    {
        $php = $this->getPhpDiagnostics();
        $extensions = $this->getExtensionDiagnostics();
        $storage = $this->getStorageDiagnostics();
        $database = $this->getDatabaseDiagnostics();
        $limits = $this->getLimitsDiagnostics();
        $scanner = $this->getScannerDiagnostics();
        $worker = $this->getWorkerDiagnostics();
        $activity = $this->getActivityDiagnostics();

        $allOk = $php['status'] === 'ok'
            && $extensions['status'] === 'ok'
            && $storage['status'] === 'ok'
            && $database['status'] === 'ok'
            && $limits['status'] === 'ok'
            && $scanner['status'] !== 'error'
            && $worker['status'] === 'ok'
            && $activity['status'] === 'ok';

        $hasWarnings = $php['status'] === 'warning'
            || $extensions['status'] === 'warning'
            || $storage['status'] === 'warning'
            || $database['status'] === 'warning'
            || $limits['status'] === 'warning'
            || $scanner['status'] === 'warning'
            || $worker['status'] === 'warning'
            || $activity['status'] === 'warning';

        $overallStatus = $allOk ? 'healthy' : ($hasWarnings ? 'warning' : 'critical');

        return [
            'overall_status' => $overallStatus,
            'timestamp' => now()->toIso8601String(),
            'php' => $php,
            'extensions' => $extensions,
            'storage' => $storage,
            'database' => $database,
            'limits' => $limits,
            'scanner' => $scanner,
            'worker' => $worker,
            'activity' => $activity,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPhpDiagnostics(): array
    {
        $version = PHP_VERSION;
        $majorMinor = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        $isSupported = version_compare($version, '8.3.0', '>=');
        $opcacheEnabled = function_exists('opcache_get_status') && is_array(@opcache_get_status());

        return [
            'status' => $isSupported ? 'ok' : 'warning',
            'version' => $version,
            'major_minor' => $majorMinor,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS,
            'opcache_enabled' => $opcacheEnabled,
            'remediation' => $isSupported ? null : 'PHP 8.3 of hoger wordt aanbevolen voor optimale prestaties en beveiliging.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtensionDiagnostics(): array
    {
        $required = [
            'pdo' => 'Vereist voor databasekoppeling.',
            'gd' => 'Vereist voor afbeeldingsverwerking en afgeleide weergaven.',
            'exif' => 'Vereist voor oriëntatie en EXIF-extractie.',
            'fileinfo' => 'Vereist voor MIME-type detectie.',
            'intl' => 'Vereist voor lokalisatie en datumopmaak.',
            'mbstring' => 'Vereist voor UTF-8 tekenreeksverwerking.',
            'openssl' => 'Vereist voor veilige communicatie en sleutelgeneratie.',
            'zip' => 'Vereist voor exportpakketten en release-acceptatie.',
        ];

        $results = [];
        $missing = [];

        foreach ($required as $ext => $purpose) {
            $loaded = extension_loaded($ext);
            $results[$ext] = [
                'loaded' => $loaded,
                'purpose' => $purpose,
            ];
            if (! $loaded) {
                $missing[] = $ext;
            }
        }

        $dbDriver = (string) config('database.default', 'pgsql');
        $pdoDriver = $dbDriver === 'pgsql' ? 'pdo_pgsql' : ($dbDriver === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql');
        $pdoLoaded = extension_loaded($pdoDriver);
        $results[$pdoDriver] = [
            'loaded' => $pdoLoaded,
            'purpose' => "Vereist voor {$dbDriver} databaseverbinding.",
        ];
        if (! $pdoLoaded) {
            $missing[] = $pdoDriver;
        }

        return [
            'status' => count($missing) === 0 ? 'ok' : 'critical',
            'extensions' => $results,
            'missing' => $missing,
            'remediation' => count($missing) === 0 ? null : 'Installeer en activeer de ontbrekende PHP-extensies: '.implode(', ', $missing).'.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getStorageDiagnostics(): array
    {
        $defaultDisk = (string) config('filesystems.default', 'local');
        $disksToCheck = [$defaultDisk];
        if ($defaultDisk !== 'local' && config('filesystems.disks.local')) {
            $disksToCheck[] = 'local';
        }
        if (config('filesystems.disks.private_local')) {
            $disksToCheck[] = 'private_local';
        }

        $diskResults = [];
        $hasError = false;

        foreach (array_unique($disksToCheck) as $diskName) {
            $diskConfig = config("filesystems.disks.{$diskName}");
            if (! is_array($diskConfig)) {
                continue;
            }

            // If S3 driver is configured, ensure bucket/key exist before attempting network probe
            if (($diskConfig['driver'] ?? '') === 's3' && empty($diskConfig['bucket'])) {
                $diskResults[$diskName] = [
                    'driver' => 's3',
                    'accessible' => false,
                    'read_write_verified' => false,
                    'error' => 'S3 bucket is niet geconfigureerd.',
                ];
                $hasError = true;

                continue;
            }

            try {
                $disk = Storage::disk($diskName);
                $testFile = '.diagnostics_probe_'.bin2hex(random_bytes(6));
                $writeOk = $disk->put($testFile, 'probe');
                $readOk = $writeOk && $disk->get($testFile) === 'probe';
                $deleteOk = $writeOk && $disk->delete($testFile);

                $accessible = $writeOk && $readOk && $deleteOk;
                if (! $accessible) {
                    $hasError = true;
                }

                $diskResults[$diskName] = [
                    'driver' => (string) ($diskConfig['driver'] ?? $diskName),
                    'accessible' => $accessible,
                    'read_write_verified' => $accessible,
                    'error' => $accessible ? null : 'Lees- of schrijftest mislukt.',
                ];
            } catch (Throwable $e) {
                $hasError = true;
                $diskResults[$diskName] = [
                    'driver' => (string) ($diskConfig['driver'] ?? $diskName),
                    'accessible' => false,
                    'read_write_verified' => false,
                    'error' => 'Toegang tot opslagschijf mislukt: '.$this->sanitizeErrorMessage($e->getMessage()),
                ];
            }
        }

        return [
            'status' => $hasError ? 'critical' : 'ok',
            'disks' => $diskResults,
            'remediation' => $hasError ? 'Controleer bestandsrechten op opslagmappen of S3 bucket permissies en netwerkverbinding.' : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDatabaseDiagnostics(): array
    {
        $start = microtime(true);
        $connected = false;
        $driver = (string) config('database.default', 'pgsql');
        $latencyMs = null;
        $error = null;

        try {
            DB::connection()->getPdo();
            $latencyMs = round((microtime(true) - $start) * 1000, 2);
            $connected = true;
        } catch (Throwable $e) {
            $error = $this->sanitizeErrorMessage($e->getMessage());
        }

        $pendingMigrations = 0;
        if ($connected) {
            try {
                /** @var Migrator $migrator */
                $migrator = app('migrator');
                $files = $migrator->getMigrationFiles($migrator->paths());
                /** @var array<string> $ran */
                $ran = DB::table('migrations')->pluck('migration')->all();
                $pendingMigrations = count(array_diff(array_keys($files), $ran));
            } catch (Throwable) {
                $pendingMigrations = 0;
            }
        }

        $status = (! $connected) ? 'critical' : ($pendingMigrations > 0 ? 'warning' : 'ok');

        return [
            'status' => $status,
            'driver' => $driver,
            'connected' => $connected,
            'latency_ms' => $latencyMs,
            'pending_migrations' => $pendingMigrations,
            'error' => $error,
            'remediation' => ! $connected
                ? 'Controleer database-host, poort, credentials en firewall instellingen.'
                : ($pendingMigrations > 0 ? "Er zijn {$pendingMigrations} niet-uitgevoerde migraties. Voer 'php artisan migrate' uit." : null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getLimitsDiagnostics(): array
    {
        $uploadMax = (string) (ini_get('upload_max_filesize') ?: '2M');
        $postMax = (string) (ini_get('post_max_size') ?: '8M');
        $memoryLimit = (string) (ini_get('memory_limit') ?: '128M');
        $maxExec = (string) (ini_get('max_execution_time') ?: '30');

        $uploadBytes = $this->parseBytes($uploadMax);
        $postBytes = $this->parseBytes($postMax);
        $appMaxBytes = (int) config('ingest.max_upload_bytes', 52428800);
        $appMaxPixels = (int) config('ingest.max_image_pixels', 100000000);

        $warnings = [];
        if ($uploadBytes < $appMaxBytes) {
            $warnings[] = "PHP upload_max_filesize ({$uploadMax}) is lager dan geconfigureerde archieflimiet (".round($appMaxBytes / 1048576, 1).'MB).';
        }
        if ($postBytes < $appMaxBytes) {
            $warnings[] = "PHP post_max_size ({$postMax}) is lager dan geconfigureerde archieflimiet (".round($appMaxBytes / 1048576, 1).'MB).';
        }

        return [
            'status' => count($warnings) > 0 ? 'warning' : 'ok',
            'php_upload_max_filesize' => $uploadMax,
            'php_post_max_size' => $postMax,
            'php_memory_limit' => $memoryLimit,
            'php_max_execution_time' => $maxExec.'s',
            'app_max_upload_bytes' => $appMaxBytes,
            'app_max_upload_mb' => round($appMaxBytes / 1048576, 1),
            'app_max_image_pixels' => $appMaxPixels,
            'warnings' => $warnings,
            'remediation' => count($warnings) > 0 ? implode(' ', $warnings) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getScannerDiagnostics(): array
    {
        $scanner = (string) config('ingest.scanner', 'none');
        if ($scanner === 'none') {
            return [
                'status' => 'warning',
                'scanner' => 'none',
                'configured' => false,
                'active' => false,
                'message' => 'Malware-scanner is uitgeschakeld (standaard voor ontwikkelomgevingen).',
                'remediation' => 'Voor productieomgevingen wordt ClamAV activering aanbevolen (ingest.scanner=clamav).',
            ];
        }

        if ($scanner === 'clamav') {
            $host = (string) config('ingest.clamav_host', '127.0.0.1');
            $port = (int) config('ingest.clamav_port', 3310);
            $timeout = (int) config('ingest.clamav_timeout', 5);

            $online = false;
            $error = null;

            try {
                $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
                if ($socket !== false) {
                    fwrite($socket, "zPING\0");
                    $reply = stream_get_line($socket, 100, "\0");
                    fclose($socket);
                    if (trim((string) $reply) === 'PONG') {
                        $online = true;
                    }
                }
            } catch (Throwable $e) {
                $error = $this->sanitizeErrorMessage($e->getMessage());
            }

            return [
                'status' => $online ? 'ok' : 'critical',
                'scanner' => 'clamav',
                'configured' => true,
                'active' => $online,
                'endpoint' => $this->maskHost($host).':'.$port,
                'message' => $online ? 'ClamAV daemon reageert correct op PING.' : 'ClamAV daemon is niet bereikbaar.',
                'error' => $error,
                'remediation' => $online ? null : 'Controleer of de ClamAV daemon draait en bereikbaar is op de geconfigureerde host en poort.',
            ];
        }

        return [
            'status' => 'warning',
            'scanner' => $scanner,
            'configured' => true,
            'active' => false,
            'message' => 'Onbekende scanner-configuratie.',
            'remediation' => 'Stel ingest.scanner in op "clamav" of "none".',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getWorkerDiagnostics(): array
    {
        $queueDriver = (string) config('queue.default', 'sync');
        $pendingJobs = 0;
        $failedJobs = 0;
        $staleClaims = 0;

        try {
            $pendingJobs = QuarantineUpload::query()->whereIn('status', ['queued', 'running'])->count();
            $failedJobs = QuarantineUpload::query()->where('status', 'failed')->count();
            $staleClaims = QuarantineUpload::query()
                ->where('status', 'running')
                ->where('started_at', '<', now()->subMinutes(5))
                ->count();
        } catch (Throwable) {
            // Table might not exist or DB error
        }

        $hasWarnings = $staleClaims > 0 || $failedJobs > 5;

        return [
            'status' => $hasWarnings ? 'warning' : 'ok',
            'queue_driver' => $queueDriver,
            'pending_ingest_jobs' => $pendingJobs,
            'failed_ingest_jobs' => $failedJobs,
            'stale_claims' => $staleClaims,
            'remediation' => $staleClaims > 0
                ? "Er zijn {$staleClaims} vastgelopen verwerkingstaken gedetecteerd. Controleer worker-processen of herstart verwerking."
                : ($failedJobs > 0 ? "Er zijn {$failedJobs} mislukte taken. Bekijk het Verwerkingscentrum voor details en herpogingen." : null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getActivityDiagnostics(): array
    {
        $roles = $this->heartbeats->snapshot();
        $missing = array_keys(array_filter($roles, static fn (array $role): bool => ! $role['seen']));
        $stale = array_keys(array_filter($roles, static fn (array $role): bool => (bool) $role['stale']));

        $status = $stale === [] ? 'ok' : 'warning';

        return [
            'status' => $status,
            'roles' => $roles,
            'missing_roles' => $missing,
            'stale_roles' => $stale,
            'remediation' => $status === 'ok'
                ? null
                : 'Laat de scheduler elke minuut lopen en start minstens een ingest-worker; diagnostics gebruikt echte heartbeats in plaats van alleen configuratie.',
        ];
    }

    private function parseBytes(string $val): int
    {
        $val = trim($val);
        if ($val === '') {
            return 0;
        }
        $last = strtolower($val[strlen($val) - 1]);
        $numeric = (int) $val;

        return match ($last) {
            'g' => $numeric * 1073741824,
            'm' => $numeric * 1048576,
            'k' => $numeric * 1024,
            default => (int) $val,
        };
    }

    private function sanitizeErrorMessage(string $msg): string
    {
        $msg = (string) preg_replace('/password=[^;\s&]+/i', 'password=********', $msg);
        $msg = (string) preg_replace('/secret=[^;\s&]+/i', 'secret=********', $msg);
        $msg = (string) preg_replace('/key=[^;\s&]+/i', 'key=********', $msg);
        $msg = (string) preg_replace('/Bearer\s+[A-Za-z0-9\-_.]+/i', 'Bearer ********', $msg);

        return $msg;
    }

    private function maskHost(string $host): string
    {
        if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return $host;
        }
        $parts = explode('.', $host);
        if (count($parts) === 4) {
            return $parts[0].'.*.*.'.$parts[3];
        }

        return substr($host, 0, 3).'***'.substr($host, -3);
    }
}
