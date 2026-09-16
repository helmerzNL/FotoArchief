<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:128'],
        ]);
        $key = 'login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429, __('auth.login.errors.too_many_attempts'));
        }
        RateLimiter::hit($key, 60);
        $credentials['email'] = strtolower($credentials['email']);
        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => __('auth.login.errors.invalid')]);
        }
        if (Auth::user()?->is_active === false) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => __('auth.login.errors.invalid')]);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('identity.session_revoked_at', $this->timestamp(Auth::user()?->session_revoked_at));

        return redirect()->intended(Auth::user()?->hasPermission('users.manage') ? '/admin' : '/admin/assets');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }
        if ($value !== null) {
            return CarbonImmutable::parse($value)->getTimestamp();
        }

        return null;
    }
}
