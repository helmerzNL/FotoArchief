<?php

declare(strict_types=1);

$base = rtrim(getenv('SMOKE_URL') ?: 'http://127.0.0.1:8767', '/');
$cookie = tempnam(sys_get_temp_dir(), 'foto-benchmark-');
require dirname(__DIR__).'/Smoke/http-client.php';
$exitCode = 0;
try {
    [$status, $html] = request('GET', '/login');
    check($status === 200, 'Benchmark login page failed.');
    [$status] = request('POST', '/login', [
        '_token' => token($html), 'email' => 'benchmark@example.test', 'password' => 'disposable-benchmark-password',
    ]);
    check($status === 302, 'Benchmark login failed (HTTP '.$status.').');
    [$status, $html] = request('GET', '/admin/assets');
    check($status === 200 && str_contains($html, 'Historische straat'), 'Benchmark did not return seeded assets.');
    check(preg_match('~/admin/assets/[0-9A-HJKMNP-TV-Z]{26}~i', $html, $match) === 1, 'Benchmark detail link missing.');
    $results = [];
    foreach (['/admin/assets' => 800, '/admin/assets?q=straat' => 800, $match[0] => 400] as $path => $limit) {
        $times = [];
        for ($iteration = 0; $iteration < 45; $iteration++) {
            $start = hrtime(true);
            [$status, $body] = request('GET', $path);
            $elapsed = (hrtime(true) - $start) / 1e6;
            check($status === 200 && str_contains($body, 'Historische straat'), 'Measured endpoint returned an incorrect result.');
            if ($iteration >= 5) {
                $times[] = $elapsed;
            }
        }
        sort($times, SORT_NUMERIC);
        $p95 = $times[(int) ceil(count($times) * 0.95) - 1];
        $results[] = ['path' => $path, 'samples' => count($times), 'p95_ms' => round($p95, 2), 'limit_ms' => $limit, 'passed' => $p95 < $limit];
    }
    echo json_encode(['records' => 50000, 'scope' => 'private-metadata-http', 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    check(! in_array(false, array_column($results, 'passed'), true), 'Performance threshold exceeded.');
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    $exitCode = 1;
} finally {
    unlink($cookie);
}
exit($exitCode);
