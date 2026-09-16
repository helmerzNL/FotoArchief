<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureActiveUserSession;
use App\Modules\Installation\InstallationBootstrap;
use App\Modules\Installation\InstallationGate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(InstallationGate::class);
        $middleware->web(append: [EnsureActiveUserSession::class]);
        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['code', 'db_password', 'secret_key', 'access_key']);
    })
    ->create();

$app->afterBootstrapping(LoadConfiguration::class, InstallationBootstrap::boot(...));

return $app;
