<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Localization\LanguagePreference;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class ApplyLanguagePreference
{
    public function __construct(private readonly LanguagePreference $preferences) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->preferences->resolve($request));

        return $next($request);
    }
}
