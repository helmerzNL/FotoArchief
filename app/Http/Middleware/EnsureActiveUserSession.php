<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUserSession
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if ($user !== null) {
            $revokedAt = $user->session_revoked_at;
            $sessionRevokedAt = null;
            if ($revokedAt instanceof DateTimeInterface) {
                $sessionRevokedAt = $revokedAt->getTimestamp();
            } elseif ($revokedAt !== null) {
                $sessionRevokedAt = CarbonImmutable::parse($revokedAt)->getTimestamp();
            }
            $sessionVersion = $request->session()->get('identity.session_revoked_at');
            if ($user->is_active === false || ($sessionRevokedAt !== null && $sessionVersion !== $sessionRevokedAt)) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect('/login')->withErrors(['email' => 'Je sessie is ingetrokken. Log opnieuw in of neem contact op met een beheerder.']);
            }
        }

        return $next($request);
    }
}
