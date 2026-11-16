<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'FotoArchief'),
    // The image the installation runs, as chosen with FOTOARCHIEF_TAG in Compose (`latest`, `beta`,
    // or a version). A tag containing `beta` is the beta channel; everything else is stable.
    // App-Guidance project-baseline.md section 20.
    'release_tag' => env('FOTOARCHIEF_TAG', 'latest'),
    'channel' => App\Support\ReleaseChannel::forTag(env('FOTOARCHIEF_TAG', 'latest')),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'Europe/Amsterdam'),
    'locale' => env('APP_LOCALE', 'nl'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'nl'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'nl_NL'),
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => [
        ...array_filter(explode(',', env('APP_PREVIOUS_KEYS', ''))),
    ],
    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],
];
