<?php

declare(strict_types=1);

use Illuminate\Http\Request;

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
$app->handleRequest(Request::capture());
