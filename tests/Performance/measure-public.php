<?php

declare(strict_types=1);

$guard = require __DIR__.'/guard.php';
$base = rtrim(getenv('SMOKE_URL') ?: 'http://127.0.0.1:8767', '/');
$cookie = tempnam($guard['root'], 'foto-public-benchmark-');
require dirname(__DIR__).'/Smoke/http-client.php';
$exitCode = 0;
try {
    [$status, $html] = request('GET', '/ontdek?q=straat&collection=benchmark-straten');
    check($status === 200 && str_contains($html, 'Historische straat'), 'Public benchmark did not return seeded assets.');
    check(preg_match('~/foto/benchmark-bench-[0-9]{6}~', $html, $match) === 1, 'Public benchmark detail link missing.');
    $results = [];
    foreach (['/ontdek?q=straat&collection=benchmark-straten' => 700, $match[0] => 400] as $path => $limit) {
        $times = [];
        for ($iteration = 0; $iteration < 45; $iteration++) {
            $start = hrtime(true);
            [$status, $body] = request('GET', $path);
            $elapsed = (hrtime(true) - $start) / 1e6;
            check($status === 200 && str_contains($body, 'Historische straat'), 'Public endpoint returned an incorrect result.');
            if ($iteration >= 5) {
                $times[] = $elapsed;
            }
        }
        sort($times, SORT_NUMERIC);
        $p95 = $times[(int) ceil(count($times) * 0.95) - 1];
        $results[] = ['path' => $path, 'samples' => count($times), 'p95_ms' => round($p95, 2), 'limit_ms' => $limit, 'passed' => $p95 < $limit];
    }
    echo json_encode(['records' => 50000, 'eligible' => 45000, 'scope' => 'anonymous-public-metadata-http', 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    check(! in_array(false, array_column($results, 'passed'), true), 'Public performance threshold exceeded.');
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    $exitCode = 1;
} finally {
    unlink($cookie);
}
exit($exitCode);
