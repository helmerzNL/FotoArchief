<?php

declare(strict_types=1);

use App\Modules\Installation\InstallationStore;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;

require dirname(__DIR__).'/vendor/autoload.php';
$configuration = json_decode((string) getenv('FOTOARCHIEF_RESTORE_ACCEPTANCE'), true, 512, JSON_THROW_ON_ERROR);
$runtime = $configuration['runtime'];
$target = $configuration['target'];
$database = $configuration['database'];
if (PHP_SAPI !== 'cli-server' || ! is_dir($runtime) || ! str_starts_with($runtime, $target.'/.acceptance-')
    || ! preg_match('/^[a-z][a-z0-9_]*_restore_drill$/D', $database['database'])) {
    throw new RuntimeException('Isolated restore acceptance configuration required.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (! in_array($path, ['/login', '/setup', '/admin/assets/'.$configuration['asset']], true)
    || ! in_array($_SERVER['REQUEST_METHOD'], $path === '/login' ? ['GET', 'POST'] : ['GET'], true)) {
    http_response_code(404);
    exit;
}
foreach ([
    'APP_ENV' => 'restore_acceptance', 'APP_DEBUG' => 'false', 'APP_URL' => $configuration['url'],
    'INSTALLATION_ENABLED' => 'true', 'LARAVEL_STORAGE_PATH' => $runtime.'/storage',
    'APP_CONFIG_CACHE' => $runtime.'/cache/config.php', 'APP_ROUTES_CACHE' => $runtime.'/cache/routes.php',
    'APP_EVENTS_CACHE' => $runtime.'/cache/events.php', 'APP_PACKAGES_CACHE' => $runtime.'/cache/packages.php',
    'APP_SERVICES_CACHE' => $runtime.'/cache/services.php',
] as $name => $value) {
    $_ENV[$name] = $_SERVER[$name] = $value;
    putenv($name.'='.$value);
}
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->addAbsoluteCachePathPrefix($runtime);
$app->useStoragePath($runtime.'/storage');
$app->useEnvironmentPath($runtime)->loadEnvironmentFrom('isolated-settings');
$app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($target, $database, $configuration): void {
    $installation = $target.'/storage/app/installation';
    $state = (new InstallationStore($installation))->read();
    if ($state === null || $state->phase !== 'complete') {
        throw new RuntimeException('Completed restored installation required.');
    }
    // Never apply restored settings: they point at the original live database.
    $app['config']->set([
        'installation.enabled' => true, 'installation.path' => $installation, 'app.key' => $state->key,
        'app.url' => $configuration['url'], 'app.debug' => false,
        'database.default' => 'pgsql', 'database.connections' => ['pgsql' => $database],
        'filesystems.default' => 'local', 'filesystems.disks' => ['local' => [
            'driver' => 'local', 'root' => $target.'/storage/app/private', 'throw' => true,
        ]],
        'session.driver' => 'file', 'session.domain' => null, 'session.secure' => false,
        'cache.default' => 'file', 'queue.default' => 'null', 'mail.default' => 'array',
        'logging.default' => 'null',
    ]);
});
$app->handleRequest(Request::capture());
