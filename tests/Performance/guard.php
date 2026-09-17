<?php

declare(strict_types=1);

$database = (string) getenv('FOTOARCHIEF_BENCH_DATABASE');
$fixture = realpath((string) getenv('FOTOARCHIEF_BENCH_ROOT'));
$url = parse_url((string) getenv('SMOKE_URL'));
$port = (string) (getenv('FOTOARCHIEF_BENCH_PORT') ?: '5432');
if (getenv('FOTOARCHIEF_DISPOSABLE_BENCH') !== '1'
    || ! preg_match('/^[a-z0-9_]+_benchmark_test$/D', $database)
    || $fixture === false || dirname($fixture) !== realpath(sys_get_temp_dir())
    || ! str_starts_with(basename($fixture), 'fotoarchief-benchmark-')
    || ! is_file($fixture.'/.disposable-benchmark-fixture')
    || ! in_array(getenv('FOTOARCHIEF_BENCH_HOST'), [false, '', '127.0.0.1'], true)
    || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
    || ! is_array($url) || ($url['host'] ?? '') !== '127.0.0.1'
    || ($url['scheme'] ?? '') !== 'http' || ! isset($url['port'])) {
    throw new RuntimeException('Explicit opt-in, marked temporary fixture and isolated loopback benchmark_test database/HTTP endpoint are required.');
}

return ['database' => $database, 'root' => $fixture];
