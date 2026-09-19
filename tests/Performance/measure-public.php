<?php

declare(strict_types=1);

use Tests\Support\PerformanceContracts;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$guard = require __DIR__.'/guard.php';
$budgets = PerformanceContracts::budgets();
$base = rtrim(getenv('SMOKE_URL') ?: 'http://127.0.0.1:8767', '/');
$cookie = tempnam($guard['root'], 'foto-public-benchmark-');
require dirname(__DIR__).'/Smoke/http-client.php';
$exitCode = 0;
try {
    [$status, $html] = smokeRequest('GET', '/ontdek?q=straat&collection=benchmark-straten');
    check($status === 200 && str_contains($html, 'Historische straat'), 'Public benchmark did not return seeded assets.');
    check(preg_match('~/foto/benchmark-bench-[0-9]{6}~', $html, $match) === 1, 'Public benchmark detail link missing.');
    $results = [];
    foreach ([
        '/ontdek?q=straat&collection=benchmark-straten' => $budgets['p95_ms']['public_search'],
        $match[0] => $budgets['p95_ms']['public_detail'],
    ] as $path => $limit) {
        $times = [];
        $iterations = $budgets['samples']['warmup'] + $budgets['samples']['measured'];
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $start = hrtime(true);
            [$status, $body] = smokeRequest('GET', $path);
            $elapsed = (hrtime(true) - $start) / 1e6;
            check($status === 200 && str_contains($body, 'Historische straat'), 'Public endpoint returned an incorrect result.');
            if ($iteration >= $budgets['samples']['warmup']) {
                $times[] = $elapsed;
            }
        }
        sort($times, SORT_NUMERIC);
        $p95 = $times[(int) ceil(count($times) * 0.95) - 1];
        $results[] = ['path' => $path, 'samples' => count($times), 'p95_ms' => round($p95, 2), 'limit_ms' => $limit, 'passed' => $p95 < $limit];
    }
    PerformanceContracts::writeResult($guard['root'], 'public-http', [
        'schema_version' => 1,
        'budget_contract' => 'budgets.v1.json',
        'records' => $budgets['dataset_records'],
        'eligible' => 45000,
        'scope' => 'anonymous-public-metadata-http',
        'results' => $results,
    ]);
    check(! in_array(false, array_column($results, 'passed'), true), 'Public performance threshold exceeded.');
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    $exitCode = 1;
} finally {
    unlink($cookie);
}
exit($exitCode);
