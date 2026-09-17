<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require __DIR__.'/release-fixture.php';

if (getenv('FOTOARCHIEF_DISPOSABLE_WEBHOSTING_UPGRADE') !== '1') {
    throw new RuntimeException('Only a disposable release-upgrade fixture is allowed.');
}
if ($argc !== 3) {
    throw new RuntimeException('Usage: webhosting-release-upgrade.php previous.zip current.zip');
}
[$script, $previous, $current] = $argv;
$database = (string) getenv('FOTOARCHIEF_RELEASE_DATABASE');
if (! preg_match('/^[a-z0-9_]+_release_test$/D', $database)) {
    throw new RuntimeException('Supply an empty PostgreSQL database ending in _release_test.');
}
$host = getenv('FOTOARCHIEF_RELEASE_DB_HOST') ?: '127.0.0.1';
$port = getenv('FOTOARCHIEF_RELEASE_DB_PORT') ?: '5432';
$user = getenv('FOTOARCHIEF_RELEASE_DB_USER') ?: 'fotoarchief';
$password = getenv('FOTOARCHIEF_RELEASE_DB_PASSWORD') ?: '';
$pdo = new PDO("pgsql:host={$host};port={$port};dbname={$database}", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ((int) $pdo->query("SELECT count(*) FROM pg_tables WHERE schemaname = 'public'")->fetchColumn() !== 0) {
    throw new RuntimeException('Release-upgrade database must be empty; no existing database is modified.');
}
$root = sys_get_temp_dir().'/fotoarchief-release-upgrade-'.bin2hex(random_bytes(8));
$installed = $root.'/installed';
mkdir($installed, 0700, true);
$server = $acceptance = null;

try {
    echo "Installing the previous production ZIP into an empty PostgreSQL fixture.\n";
    $oldVersion = extractRelease($previous, $installed);
    require $installed.'/vendor/autoload.php';
    $address = releaseLoopbackAddress();
    $environment = [
        'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://'.$address,
        'INGEST_SCANNER' => 'none', 'SMOKE_URL' => 'http://'.$address,
        'SMOKE_DB_HOST' => $host, 'SMOKE_DB_PORT' => $port, 'SMOKE_DB_DATABASE' => $database,
        'SMOKE_DB_USER' => $user, 'DB_PASSWORD' => $password,
        'SMOKE_SETUP_CODE_FILE' => $installed.'/storage/app/installation/setup-code.txt',
        'SMOKE_RESTORE' => '0',
    ];
    $command = static fn (array $arguments) => releaseArtisan($installed, $environment, $arguments);
    $command(['installation:prepare', '--quiet-code']);
    $server = startReleaseServer($installed, $environment);
    runReleaseOnboarding($installed, $environment);
    $server->stop();
    $command(['queue:work', 'ingest', '--stop-when-empty', '--tries=3']);
    $before = privateSnapshot($installed);
    $filesBefore = $pdo->query('SELECT id, storage_key, sha256, storage_disk FROM asset_files ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if ($filesBefore === []) {
        throw new RuntimeException('Previous release did not persist an original.');
    }
    $newVersion = extractRelease($current, $installed);
    if (! version_compare($newVersion, $oldVersion, '>')) {
        throw new RuntimeException('Upgrade acceptance requires two distinct, increasing release versions.');
    }
    echo "Applying release {$newVersion} migrations and checking preserved private data.\n";
    $command(['installation:migrate-ready']);
    $command(['installation:migrate-ready']);
    $command(['installation:ready']);
    if ($before !== privateSnapshot($installed)
        || $filesBefore !== $pdo->query('SELECT id, storage_key, sha256, storage_disk FROM asset_files ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)) {
        throw new RuntimeException('Upgrade changed installation key/state or private file identities/checksums.');
    }
    if ((int) $pdo->query('SELECT count(*) FROM migrations')->fetchColumn() !== count(glob($installed.'/database/migrations/*.php'))) {
        throw new RuntimeException('Upgrade did not apply every packaged migration.');
    }
    $server = startReleaseServer($installed, $environment);
    $acceptance = new Process([PHP_BINARY, __DIR__.'/http-onboarding.php'], $installed, array_replace($environment, ['SMOKE_RESTORE' => '1']), timeout: 90);
    $acceptance->mustRun();
    echo "ZIP {$oldVersion} -> {$newVersion}: real PostgreSQL, installer, account, queue, original/preview bytes, private access and repeatable migrations passed.\n";
} finally {
    $acceptance?->stop();
    $server?->stop();
    removeReleaseFixture($root);
}
