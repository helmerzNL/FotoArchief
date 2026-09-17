<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$guard = require __DIR__.'/guard.php';
$database = $guard['database'];
$fixture = $guard['root'];
$root = dirname(__DIR__, 2);
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'APP_URL' => (string) getenv('SMOKE_URL'),
    'INSTALLATION_ENABLED' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('b', 32)),
    'LARAVEL_STORAGE_PATH' => $fixture.'/storage',
    'APP_CONFIG_CACHE' => $fixture.'/cache/config.php',
    'APP_ROUTES_CACHE' => $fixture.'/cache/routes.php',
    'APP_EVENTS_CACHE' => $fixture.'/cache/events.php',
    'APP_PACKAGES_CACHE' => $fixture.'/cache/packages.php',
    'APP_SERVICES_CACHE' => $fixture.'/cache/services.php',
    'SESSION_DRIVER' => 'file',
    'CACHE_STORE' => 'file',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->addAbsoluteCachePathPrefix($fixture);
$app->useStoragePath($fixture.'/storage');
$app->useEnvironmentPath($fixture)->loadEnvironmentFrom('fixture-settings');
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI === 'cli') {
    set_exception_handler(static function (Throwable $error): never {
        fwrite(STDERR, $error::class.': '.$error->getMessage().PHP_EOL);
        exit(1);
    });
}
config([
    'installation.enabled' => false,
    'database.default' => 'pgsql',
    'database.connections.pgsql.host' => '127.0.0.1',
    'database.connections.pgsql.port' => getenv('FOTOARCHIEF_BENCH_PORT') ?: 5432,
    'database.connections.pgsql.database' => $database,
    'database.connections.pgsql.username' => getenv('FOTOARCHIEF_BENCH_USER') ?: 'fotoarchief',
    'database.connections.pgsql.password' => getenv('FOTOARCHIEF_BENCH_PASSWORD') ?: '',
    'session.driver' => 'file',
]);
DB::purge('pgsql');

return $app;
