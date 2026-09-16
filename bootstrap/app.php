<?php

declare(strict_types=1);

use App\Http\Middleware\ApplyLanguagePreference;
use App\Http\Middleware\EnsureActiveUserSession;
use App\Modules\Installation\InstallationBootstrap;
use App\Modules\Installation\InstallationGate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(InstallationGate::class);
        $middleware->web(append: [ApplyLanguagePreference::class, EnsureActiveUserSession::class]);
        $middleware->redirectGuestsTo('/login');
        $trustedProxies = array_values(array_filter(array_map(
            static fn (string $proxy): string => trim($proxy),
            explode(',', (string) env('TRUSTED_PROXIES', '')),
        )));
        if ($trustedProxies !== []) {
            $middleware->trustProxies(
                at: $trustedProxies === ['*'] ? '*' : $trustedProxies,
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO,
            );
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['code', 'db_password', 'secret_key', 'access_key']);
    })
    ->create();

$app->afterBootstrapping(LoadConfiguration::class, InstallationBootstrap::boot(...));

return $app;
