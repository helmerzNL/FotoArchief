<?php

declare(strict_types=1);

namespace App\Modules\Installation;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class InstallationGate
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('installation.enabled') && ! $request->is('up')) {
            $complete = app(InstallationStore::class)->completed();
            if ($complete && $request->is('setup', 'setup/*')) {
                abort(404);
            }
            if (! $complete && ! $request->is('setup', 'setup/*')) {
                return $request->expectsJson() || $request->is('api/*')
                    ? response()->json(['message' => __('onboarding.setup.errors.not_complete')], 503)
                    : redirect('/setup');
            }
        }

        $response = $next($request);
        if ($request->is('setup', 'setup/*', 'login', 'admin', 'admin/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'same-origin');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        return $response;
    }
}
