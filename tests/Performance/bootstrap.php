<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$database = getenv('FOTOARCHIEF_BENCH_DATABASE');
if (! is_string($database) || ! str_ends_with($database, '_benchmark_test')) {
    throw new RuntimeException('Explicit isolated database ending _benchmark_test is required.');
}
$root = dirname(__DIR__, 2);
foreach ([
    'APP_ENV' => 'testing',
    'INSTALLATION_ENABLED' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('b', 32)),
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useEnvironmentPath($root.'/tests/Fixtures')->loadEnvironmentFrom('test-settings');
$app->make(Kernel::class)->bootstrap();
config([
    'installation.enabled' => false,
    'database.default' => 'pgsql',
    'database.connections.pgsql.host' => getenv('FOTOARCHIEF_BENCH_HOST') ?: '127.0.0.1',
    'database.connections.pgsql.port' => getenv('FOTOARCHIEF_BENCH_PORT') ?: 5432,
    'database.connections.pgsql.database' => $database,
    'database.connections.pgsql.username' => getenv('FOTOARCHIEF_BENCH_USER') ?: 'fotoarchief',
    'database.connections.pgsql.password' => getenv('FOTOARCHIEF_BENCH_PASSWORD') ?: '',
    'session.driver' => 'file',
]);
DB::purge('pgsql');

return $app;
