<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$started = microtime(true);

if (PHP_SAPI === 'cli-server') {
    $public = realpath(dirname(__DIR__, 2).'/public');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $file = is_string($public) && is_string($path) ? realpath($public.'/'.rawurldecode($path)) : false;
    if (is_string($file)
        && is_file($file)
        && str_starts_with($file, $public.DIRECTORY_SEPARATOR)
        && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['css', 'js', 'ico', 'png', 'jpg', 'jpeg', 'webp', 'woff', 'woff2'], true)) {
        return false;
    }
}

$app = require __DIR__.'/bootstrap.php';
$databaseMilliseconds = 0.0;
DB::listen(static function (QueryExecuted $query) use (&$databaseMilliseconds): void {
    $databaseMilliseconds += $query->time;
});
$kernel = $app->make(Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->headers->set('X-Benchmark-Pid', (string) getmypid());
$response->headers->set('X-Benchmark-Started', sprintf('%.6f', $started));
$response->headers->set('X-Benchmark-Finished', sprintf('%.6f', microtime(true)));
$response->headers->set('X-Benchmark-Db-Ms', sprintf('%.3f', $databaseMilliseconds));
$response->send();
$kernel->terminate($request, $response);
