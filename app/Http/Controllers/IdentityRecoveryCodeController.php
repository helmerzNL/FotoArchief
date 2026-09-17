<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Identity\Services\RecoveryCodeService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class IdentityRecoveryCodeController extends Controller
{
    public function regenerate(Request $request, RecoveryCodeService $recoveryCodes): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);
        $codes = $recoveryCodes->regenerate($user);

        return redirect()->route('identity.security.show')->with([
            'status' => __('identity.generated.t_77bf1199d42a5f8c'),
            'recovery_codes' => $codes,
        ]);
    }

    public function login(Request $request, RecoveryCodeService $recoveryCodes): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'code' => ['required', 'string', 'max:64'],
        ]);
        $key = 'recovery-login:'.$request->ip().':'.strtolower($data['email']);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429, __('identity.generated.t_76f15c932d4958df'));
        }
        RateLimiter::hit($key, 60);

        $user = $recoveryCodes->consume($data['email'], $data['code']);
        if ($user === null) {
            throw ValidationException::withMessages(['email' => __('identity.generated.t_1527cf521a8b17c4')]);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('identity.session_revoked_at', $this->timestamp($user->session_revoked_at));
        RateLimiter::clear($key);

        return redirect()->intended($user->hasPermission('users.manage') ? '/admin' : '/admin/assets');
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
