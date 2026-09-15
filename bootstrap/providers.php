<?php

declare(strict_types=1);
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\InstallationServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    InstallationServiceProvider::class,
];
