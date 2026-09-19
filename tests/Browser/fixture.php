<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

if (getenv('FOTOARCHIEF_DISPOSABLE_BROWSER') !== '1') {
    throw new RuntimeException('Opt in with FOTOARCHIEF_DISPOSABLE_BROWSER=1; no existing installation is used.');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/Smoke/release-fixture.php';

$database = (string) getenv('FOTOARCHIEF_BROWSER_DATABASE');
if (! preg_match('/^[a-z0-9_]+_browser_test$/D', $database)) {
    throw new RuntimeException('Supply an empty PostgreSQL database ending in _browser_test.');
}
$port = (string) getenv('FOTOARCHIEF_BROWSER_DB_PORT');
$user = (string) getenv('FOTOARCHIEF_BROWSER_DB_USER');
if (! ctype_digit($port) || $user === '') {
    throw new RuntimeException('Explicit loopback database port and disposable user are required.');
}
$pdo = new PDO("pgsql:host=127.0.0.1;port={$port};dbname={$database}", $user, 'disposable-fixture-password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ((int) $pdo->query("SELECT count(*) FROM pg_tables WHERE schemaname='public'")->fetchColumn() !== 0) {
    throw new RuntimeException('Refusing a nonempty browser fixture database.');
}
$pdo = null;
$root = sys_get_temp_dir().'/fotoarchief-browser-'.bin2hex(random_bytes(8));
foreach (['cache', 'storage/app', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $directory) {
    mkdir($root.'/'.$directory, 0700, true);
}
file_put_contents($root.'/.disposable-browser-fixture', "synthetic browser acceptance only\n");
file_put_contents($root.'/fixture-settings', '');
$url = 'http://'.releaseLoopbackAddress();
$environment = [
    'FOTOARCHIEF_BROWSER_ROOT' => $root, 'FOTOARCHIEF_BROWSER_DATABASE' => $database,
    'FOTOARCHIEF_BROWSER_DB_PORT' => $port, 'FOTOARCHIEF_BROWSER_DB_USER' => $user,
    'SMOKE_URL' => $url, 'SMOKE_SETUP_CODE_FILE' => $root.'/storage/app/installation/setup-code.txt',
    'SMOKE_DB_HOST' => '127.0.0.1', 'SMOKE_DB_PORT' => $port,
    'SMOKE_DB_DATABASE' => $database, 'SMOKE_DB_USER' => $user,
    'DB_PASSWORD' => 'disposable-fixture-password', 'SMOKE_STORAGE_DISK' => 'local', 'SMOKE_RESTORE' => '0',
];
$cwd = dirname(__DIR__, 2);
$server = $worker = $outbox = null;
try {
    (new Process([PHP_BINARY, __DIR__.'/console.php', 'installation:prepare', '--quiet-code'], $cwd, $environment, timeout: 120))->mustRun();
    $server = startReleaseServer($cwd, $environment, __DIR__.'/router.php');
    runReleaseOnboarding($cwd, $environment, __DIR__.'/console.php');
    (new Process([PHP_BINARY, __DIR__.'/seed.php'], $cwd, $environment, timeout: 120))->mustRun();
    $worker = new Process([PHP_BINARY, __DIR__.'/console.php', 'queue:work', 'ingest', '--sleep=1', '--tries=3'], $cwd, $environment, timeout: 3600);
    $worker->start();
    $outbox = new Process([PHP_BINARY, __DIR__.'/outbox-worker.php'], $cwd, $environment, timeout: 3600);
    $outbox->start();
    echo json_encode(['url' => $url, 'root' => $root, 'manifest' => $root.'/manifest.json'], JSON_THROW_ON_ERROR).PHP_EOL;
    if (! $worker->isRunning() || ! $outbox->isRunning() || ! $server->isRunning()) {
        throw new RuntimeException('Browser fixture server, outbox dispatcher, or queue worker failed to start.');
    }
    $test = null;
    if (in_array('--run', $argv, true)) {
        $test = new Process(['node', __DIR__.'/node_modules/@playwright/test/cli.js', 'test'], __DIR__, [
            'FOTOARCHIEF_BROWSER_MANIFEST' => $root.'/manifest.json',
        ], timeout: 1200);
        $test->start(function (string $type, string $buffer): void {
            fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
        });
    }
    while (! is_file($root.'/stop') && ($test === null || $test->isRunning())) {
        if (! $server->isRunning() || ! $outbox->isRunning() || ! $worker->isRunning()) {
            throw new RuntimeException(
                'Browser fixture stopped unexpectedly: '
                .$server->getErrorOutput()
                .$outbox->getErrorOutput()
                .$outbox->getOutput()
                .$worker->getErrorOutput()
                .$worker->getOutput()
            );
        }
        $server->checkTimeout();
        $worker->checkTimeout();
        $test?->checkTimeout();
        usleep(200000);
    }
    if ($test !== null && (! $test->isTerminated() || $test->getExitCode() !== 0)) {
        throw new RuntimeException('Browser acceptance failed; inspect the Playwright report.');
    }
} finally {
    if (isset($test)) {
        $test->stop();
    }
    $outbox?->stop();
    $worker?->stop();
    $server?->stop();
    removeReleaseFixture($root);
}
