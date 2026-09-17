<?php

declare(strict_types=1);

$root = realpath((string) getenv('FOTOARCHIEF_BROWSER_ROOT'));
$database = (string) getenv('FOTOARCHIEF_BROWSER_DATABASE');
$prefix = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'fotoarchief-browser-';
if ($root === false || ! str_starts_with($root, $prefix)
    || ! is_file($root.'/.disposable-browser-fixture')
    || ! preg_match('/^[a-z0-9_]+_browser_test$/D', $database)) {
    throw new RuntimeException('Browser tests require a marked temporary fixture and a dedicated browser_test database.');
}
$port = (string) getenv('FOTOARCHIEF_BROWSER_DB_PORT');
if (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
    throw new RuntimeException('Supply the disposable loopback PostgreSQL port.');
}
foreach ([
    'APP_ENV' => 'browser_acceptance', 'APP_DEBUG' => 'false',
    'APP_URL' => (string) getenv('SMOKE_URL'), 'APP_KEY' => '',
    'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => $port,
    'DB_DATABASE' => $database, 'DB_USERNAME' => (string) getenv('FOTOARCHIEF_BROWSER_DB_USER'),
    'DB_PASSWORD' => 'disposable-fixture-password', 'FILESYSTEM_DISK' => 'local',
    'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'INGEST_SCANNER' => 'none',
    'LARAVEL_STORAGE_PATH' => $root.'/storage',
    'APP_CONFIG_CACHE' => $root.'/cache/config.php',
    'APP_ROUTES_CACHE' => $root.'/cache/routes.php',
    'APP_EVENTS_CACHE' => $root.'/cache/events.php',
    'APP_PACKAGES_CACHE' => $root.'/cache/packages.php',
    'APP_SERVICES_CACHE' => $root.'/cache/services.php',
] as $name => $value) {
    $_ENV[$name] = $_SERVER[$name] = $value;
    putenv($name.'='.$value);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->addAbsoluteCachePathPrefix($root);
$app->useStoragePath($root.'/storage');
$app->useEnvironmentPath($root)->loadEnvironmentFrom('fixture-settings');

return $app;
