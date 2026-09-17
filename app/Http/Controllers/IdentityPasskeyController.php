<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Identity\Models\UserPasskey;
use App\Modules\Identity\Services\WebAuthnCeremony;
use App\Modules\Identity\Services\WebAuthnChallengeRepository;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class IdentityPasskeyController extends Controller
{
    public function enrollmentOptions(Request $request, WebAuthnCeremony $ceremony, WebAuthnChallengeRepository $challenges): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);
        $result = $ceremony->enrollmentOptions($user);
        $challenge = $challenges->store('enrollment', $result['challenge'], $user);
        $request->session()->put('identity.enrollment_challenge_id', $challenge->id);

        return response()->json($result['options']);
    }

    public function store(Request $request, WebAuthnCeremony $ceremony, WebAuthnChallengeRepository $challenges): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'id' => ['nullable', 'string'],
            'clientDataJSON' => ['required', 'string'],
            'attestationObject' => ['required', 'string'],
            'transports' => ['nullable', 'array'],
            'transports.*' => ['string', 'in:usb,nfc,ble,hybrid,internal'],
        ]);

        $challengeId = $request->session()->pull('identity.enrollment_challenge_id');
        if (! is_string($challengeId)) {
            throw ValidationException::withMessages(['passkey' => __('identity.generated.t_2b3724b0f713a540')]);
        }

        DB::transaction(function () use ($challengeId, $challenges, $ceremony, $data, $user): void {
            $challenge = $challenges->consume($challengeId, 'enrollment');
            if ($challenge->user_id !== $user->id) {
                throw ValidationException::withMessages(['passkey' => __('identity.generated.t_2b3724b0f713a540')]);
            }
            $passkey = $ceremony->verifyEnrollment($challenge, $data);
            UserPasskey::query()->create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'credential_id' => $passkey->credentialId,
                'credential_public_key' => $passkey->credentialPublicKey,
                'signature_counter' => $passkey->signatureCounter,
                'transports' => $passkey->transports,
            ]);
        });

        return response()->json(['ok' => true], 201);
    }

    public function destroy(Request $request, UserPasskey $passkey): RedirectResponse
    {
        abort_unless($request->user()?->id === $passkey->user_id, 403);
        $passkey->delete();

        return redirect()->route('identity.security.show')->with('status', __('identity.generated.t_cb851ea3faafc8bb'));
    }

    public function loginOptions(Request $request, WebAuthnCeremony $ceremony, WebAuthnChallengeRepository $challenges): JsonResponse
    {
        $result = $ceremony->loginOptions();
        $challenge = $challenges->store('login', $result['challenge'], null);
        $request->session()->put('identity.login_challenge_id', $challenge->id);

        return response()->json($result['options']);
    }

    public function login(Request $request, WebAuthnCeremony $ceremony, WebAuthnChallengeRepository $challenges): JsonResponse
    {
        $key = 'passkey-login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            abort(429, __('identity.generated.t_6426f54ad4880ff7'));
        }
        RateLimiter::hit($key, 60);

        $data = $request->validate([
            'id' => ['required', 'string'],
            'clientDataJSON' => ['required', 'string'],
            'authenticatorData' => ['required', 'string'],
            'signature' => ['required', 'string'],
            'userHandle' => ['nullable', 'string'],
        ]);

        $challengeId = $request->session()->pull('identity.login_challenge_id');
        if (! is_string($challengeId)) {
            throw ValidationException::withMessages(['passkey' => __('identity.generated.t_0352497aa8179233')]);
        }

        DB::transaction(function () use ($challengeId, $challenges, $ceremony, $data, $request): void {
            $challenge = $challenges->consume($challengeId, 'login');
            $passkey = UserPasskey::query()
                ->where('credential_id', $ceremony->credentialIdFromPayload($data))
                ->lockForUpdate()
                ->first();

            if (! $passkey instanceof UserPasskey) {
                throw ValidationException::withMessages(['passkey' => __('identity.generated.t_0352497aa8179233')]);
            }

            $user = $passkey->user()->first();
            if ($user === null || $user->is_active === false || $ceremony->userHandleFromPayload($data) !== $user->id) {
                throw ValidationException::withMessages(['passkey' => __('identity.generated.t_0352497aa8179233')]);
            }

            $ceremony->verifyLogin($passkey, $challenge, $data);
            $passkey->forceFill([
                'signature_counter' => $ceremony->signatureCounter(),
                'last_used_at' => now(),
            ])->save();
            Auth::login($user);
            $request->session()->regenerate();
            $request->session()->put('identity.session_revoked_at', $this->timestamp($user->session_revoked_at));
        });
        RateLimiter::clear($key);

        return response()->json(['ok' => true, 'redirect' => Auth::user()?->hasPermission('users.manage') ? '/admin' : '/admin/assets']);
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
