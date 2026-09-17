<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/Smoke/release-fixture.php';

if (getenv('FOTOARCHIEF_DISPOSABLE_BENCH') !== '1'
    || ! preg_match('/^[a-z0-9_]+_benchmark_test$/D', (string) getenv('FOTOARCHIEF_BENCH_DATABASE'))) {
    throw new RuntimeException('Opt in with FOTOARCHIEF_DISPOSABLE_BENCH=1 and an empty *_benchmark_test database.');
}
$source = dirname(__DIR__, 2);
$root = sys_get_temp_dir().'/fotoarchief-benchmark-'.bin2hex(random_bytes(8));
$processes = [];
$failed = [];
mkdir($root, 0700);
file_put_contents($root.'/.disposable-benchmark-fixture', 'synthetic only');
foreach (['cache', 'storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $directory) {
    mkdir($root.'/'.$directory, 0700, true);
}
$environment = [
    'FOTOARCHIEF_BENCH_ROOT' => $root,
    'SMOKE_URL' => 'http://'.releaseLoopbackAddress(),
];
try {
    foreach ([
        ['FOTOARCHIEF_DISPOSABLE_BENCH' => '0'],
        ['FOTOARCHIEF_BENCH_DATABASE' => 'postgres'],
        ['FOTOARCHIEF_BENCH_HOST' => '192.0.2.1'],
        ['SMOKE_URL' => 'https://example.invalid'],
    ] as $unsafe) {
        $refusal = new Process([PHP_BINARY, __DIR__.'/seed.php'], $source, array_replace($environment, $unsafe), timeout: 15);
        $refusal->run();
        if ($refusal->isSuccessful() || ! str_contains($refusal->getErrorOutput(), 'Explicit opt-in')) {
            throw new RuntimeException('Unsafe benchmark configuration was not refused before access.');
        }
    }
    foreach (['seed.php', 'seed-public.php'] as $script) {
        $seed = new Process([PHP_BINARY, __DIR__.'/'.$script], $source, $environment, timeout: 600);
        $seed->mustRun();
        echo $seed->getOutput();
    }
    $refusal = new Process([PHP_BINARY, __DIR__.'/seed.php'], $source, $environment, timeout: 15);
    $refusal->run();
    if ($refusal->isSuccessful() || ! str_contains($refusal->getErrorOutput(), 'existing data will not be replaced')) {
        throw new RuntimeException('Nonempty database was not refused.');
    }
    echo "Opt-in, database-name, remote-host, remote-HTTP and nonempty database guards passed.\n";
    $workers = [];
    for ($index = 0; $index < 4; $index++) {
        $workerEnvironment = array_replace($environment, ['SMOKE_URL' => 'http://'.releaseLoopbackAddress()]);
        $processes[] = startReleaseServer($source, $workerEnvironment, __DIR__.'/router.php');
        $workers[] = $workerEnvironment['SMOKE_URL'];
    }
    file_put_contents($root.'/provider-mode', 'normal');
    file_put_contents($root.'/pool.json', json_encode([
        'url' => $environment['SMOKE_URL'], 'workers' => $workers, 'root' => $root,
    ], JSON_THROW_ON_ERROR));
    $proxy = new Process(['node', __DIR__.'/pool.mjs', $root.'/pool.json'], $source, $environment, timeout: null);
    $processes[] = $proxy;
    $proxy->start();
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $curl = curl_init($environment['SMOKE_URL'].'/__fixture_health');
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
        $ready = curl_exec($curl) === 'disposable benchmark';
        unset($curl);
        if ($ready || ! $proxy->isRunning()) {
            break;
        }
        usleep(100000);
    }
    if (! $ready) {
        throw new RuntimeException('Benchmark pool did not become responsive: '.$proxy->getErrorOutput());
    }
    foreach (['measure.php', 'measure-public.php'] as $script) {
        $measurement = new Process([PHP_BINARY, __DIR__.'/'.$script], $source, $environment, timeout: 600);
        $measurement->run();
        echo $measurement->getOutput();
        fwrite(STDERR, $measurement->getErrorOutput());
        if (! $measurement->isSuccessful()) {
            $failed[] = $script;
        }
    }
    $vectors = new Process([PHP_BINARY, __DIR__.'/seed-vectors.php'], $source, $environment, timeout: 600);
    $vectors->mustRun();
    echo $vectors->getOutput();
    foreach ([
        [PHP_BINARY, __DIR__.'/measure-vectors.php'],
        [PHP_BINARY, __DIR__.'/relevance.php'],
        ['node', __DIR__.'/concurrent.mjs', $environment['SMOKE_URL']],
    ] as $arguments) {
        $measurement = new Process($arguments, $source, $environment, timeout: 600);
        $measurement->run();
        echo $measurement->getOutput();
        fwrite(STDERR, $measurement->getErrorOutput());
        if (! $measurement->isSuccessful()) {
            $failed[] = basename($arguments[1]);
        }
    }
    file_put_contents($root.'/provider-mode', 'wrong-ranking');
    $negative = new Process([PHP_BINARY, __DIR__.'/relevance.php', '--negative-control'], $source, $environment, timeout: 120);
    $negative->run();
    echo $negative->getOutput();
    if ($negative->getExitCode() !== 1 || ! str_contains($negative->getErrorOutput(), 'Relevance thresholds not met')) {
        throw new RuntimeException('Wrong-provider negative control did not fail the actual route relevance threshold.');
    }
    foreach ($processes as $process) {
        if (! $process->isRunning()) {
            throw new RuntimeException('Owned benchmark service exited: '.$process->getErrorOutput());
        }
    }
    if ($failed !== []) {
        throw new RuntimeException('Benchmark gates failed: '.implode(', ', $failed));
    }
    echo "All bounded 50k HTTP/vector/relevance gates passed; wrong-ranking control failed as required.\n";
} finally {
    foreach (array_reverse($processes) as $process) {
        $process->stop();
    }
    removeReleaseFixture($root);
}
