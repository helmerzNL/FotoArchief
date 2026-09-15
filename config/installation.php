<?php

declare(strict_types=1);

return [
    'enabled' => env('APP_ENV') !== 'testing' || (bool) env('INSTALLATION_ENABLED', true),
    'path' => storage_path('app/installation'),
];
