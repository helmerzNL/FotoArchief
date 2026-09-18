<?php

declare(strict_types=1);

$public = realpath(dirname(__DIR__, 2).'/public');
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = realpath($public.DIRECTORY_SEPARATOR.ltrim($path, '/'));
if ($file !== false && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file)
    && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['css', 'js', 'png', 'jpg', 'jpeg', 'svg', 'ico', 'woff2', 'webmanifest'], true)) {
    return false;
}
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));
$app = require __DIR__.'/bootstrap.php';
$app->handleRequest(Request::capture());
