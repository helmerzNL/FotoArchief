<?php

declare(strict_types=1);

use Tests\Support\PerformanceContracts;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$guard = require __DIR__.'/guard.php';
$budgets = PerformanceContracts::budgets();
$base = rtrim(getenv('SMOKE_URL') ?: 'http://127.0.0.1:8767', '/');
$cookie = tempnam($guard['root'], 'foto-benchmark-');
require dirname(__DIR__).'/Smoke/http-client.php';
$exitCode = 0;
try {
    [$status, $html] = smokeRequest('GET', '/login');
    check($status === 200, 'Benchmark login page failed.');
    [$status] = smokeRequest('POST', '/login', [
        '_token' => token($html), 'email' => 'benchmark@example.test', 'password' => 'disposable-benchmark-password',
    ]);
    check($status === 302, 'Benchmark login failed (HTTP '.$status.').');
    [$status, $html] = smokeRequest('GET', '/admin/assets');
    check($status === 200 && str_contains($html, 'Historische straat'), 'Benchmark did not return seeded assets.');
    check(preg_match('~/admin/assets/[0-9A-HJKMNP-TV-Z]{26}~i', $html, $match) === 1, 'Benchmark detail link missing.');
    $detail = $match[0];
    [$status, $body] = smokeRequest('GET', $detail);
    check($status === 200 && preg_match('/BENCH-[0-9]{6}/', $body, $accession) === 1, 'Benchmark asset identity missing.');
    $results = [];
    foreach ([
        '/admin/assets' => $budgets['p95_ms']['private_listing'],
        '/admin/assets?q=straat' => $budgets['p95_ms']['private_search'],
        $detail => $budgets['p95_ms']['private_detail'],
    ] as $path => $limit) {
        $times = [];
        $iterations = $budgets['samples']['warmup'] + $budgets['samples']['measured'];
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $start = hrtime(true);
            [$status, $body] = smokeRequest('GET', $path);
            $elapsed = (hrtime(true) - $start) / 1e6;
            check($status === 200 && str_contains($body, $path === $detail ? $accession[0] : 'Historische straat'), "Measured endpoint {$path} returned an incorrect result (HTTP {$status}).");
            if ($iteration >= $budgets['samples']['warmup']) {
                $times[] = $elapsed;
            }
        }
        sort($times, SORT_NUMERIC);
        $p95 = $times[(int) ceil(count($times) * 0.95) - 1];
        $results[] = ['path' => $path, 'samples' => count($times), 'p95_ms' => round($p95, 2), 'limit_ms' => $limit, 'passed' => $p95 < $limit];
    }
    PerformanceContracts::writeResult($guard['root'], 'private-http', [
        'schema_version' => 1,
        'budget_contract' => 'budgets.v1.json',
        'records' => $budgets['dataset_records'],
        'scope' => 'private-metadata-http',
        'results' => $results,
    ]);
    check(! in_array(false, array_column($results, 'passed'), true), 'Performance threshold exceeded.');
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    $exitCode = 1;
} finally {
    unlink($cookie);
}
exit($exitCode);
