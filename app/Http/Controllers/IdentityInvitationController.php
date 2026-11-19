<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\Identity\Models\UserPasskey;
use App\Modules\Identity\Services\InvitationService;
use App\Modules\Identity\Services\WebAuthnCeremony;
use App\Modules\Identity\Services\WebAuthnChallengeRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Invitation and reset links (App-Guidance shared/guidelines/user-management.md).
 *
 * The token travels in the URL fragment (/uitnodiging#invite=<token>), which a browser never sends
 * to a server, and reaches the server only in the X-Invite-Token header or a JSON body, so it stays
 * out of access logs, Referer and browser history.
 */
class IdentityInvitationController extends Controller
{
    private const string NAME_RULE = '/^[^\p{Cc}]+$/u';

    public function __construct(private readonly InvitationService $invitations) {}

    public function create(): View
    {
        return view('identity.invitations.create', [
            'roles' => Role::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $roleIds = Role::query()->pluck('id')->all();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:'.self::NAME_RULE],
            'email' => ['required', 'email', 'max:254'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', Rule::in($roleIds)],
        ]);
        $result = $this->invitations->invite($this->actor($request), $data['name'], $data['email'], $data['roles']);

        return $this->showLinkOnce($result, __('identity.generated.t_70eb33d89d807ef1'));
    }

    public function reset(Request $request, User $user): RedirectResponse
    {
        $result = $this->invitations->reset($this->actor($request), $user);

        return $this->showLinkOnce($result, __('identity.invitations.reset_created', ['name' => $user->name]));
    }

    public function revoke(Request $request, UserInvitation $invitation): RedirectResponse
    {
        $this->invitations->revoke($this->actor($request), $invitation);

        return redirect()->route('identity.users.index')->with('status', __('identity.invitations.revoked'));
    }

    /** The guest's page. It holds no token; the script reads it from the fragment. */
    public function show(): View
    {
        return view('identity.invitations.accept', [
            'passwordMin' => 14,
        ]);
    }

    /** Links made before tokens moved to the fragment still work, once, through the page. */
    public function legacy(string $token): RedirectResponse
    {
        return redirect()->to(route('identity.invitations.show', [], false).'#invite='.rawurlencode($token));
    }

    /** Public: what the page needs and nothing more. Every bad token gets the same 404. */
    public function info(Request $request): JsonResponse
    {
        $invitation = $this->invitations->find($request->header('X-Invite-Token'));
        if (! $invitation instanceof UserInvitation) {
            return $this->notFound();
        }

        return response()->json([
            'kind' => $invitation->kind,
            'name' => $invitation->name,
            'email' => $invitation->email,
            'invitedBy' => $invitation->inviter()->value('name'),
            'expiresAt' => $invitation->expires_at->toIso8601String(),
            'expiresAtLocal' => $invitation->expires_at->timezone(config('app.timezone'))->format('d-m-Y H:i'),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:255', 'regex:'.self::NAME_RULE],
            'password' => ['required', 'string', 'min:14', 'max:128', 'confirmed'],
        ]);
        $user = $this->invitations->complete($data['token'], $data['name'] ?? null, true, $data['password'], static function (): void {});

        return $this->signIn($request, $user);
    }

    public function passkeyOptions(Request $request, WebAuthnCeremony $ceremony, WebAuthnChallengeRepository $challenges): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:255', 'regex:'.self::NAME_RULE],
        ]);
        $invitation = $this->invitations->find($data['token']);
        if (! $invitation instanceof UserInvitation) {
            return $this->notFound();
        }
        // A taken address is refused before a passkey is made for it.
        if (! $invitation->isReset() && User::query()->where('email', $invitation->email)->exists()) {
            throw ValidationException::withMessages(['token' => __('identity.generated.t_98562daa70f652a7')]);
        }
        $userId = $invitation->isReset() ? (string) $invitation->user_id : strtolower((string) Str::ulid());
        $displayName = $invitation->isReset() ? $invitation->name : (trim((string) ($data['name'] ?? '')) ?: $invitation->name);
        $result = $ceremony->enrollmentOptionsFor($userId, $invitation->email, $displayName);
        $challenge = $challenges->store('invitation', $result['challenge'], null);
        $request->session()->put('identity.invitation', [
            'challenge_id' => $challenge->id,
            'token_hash' => $invitation->token_hash,
            'user_id' => $userId,
        ]);

        return response()->json($result['options']);
    }

    public function passkeyStore(Request $request, WebAuthnCeremony $ceremony, WebAuthnChallengeRepository $challenges): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:255', 'regex:'.self::NAME_RULE],
            'passkeyName' => ['nullable', 'string', 'max:100'],
            'id' => ['nullable', 'string'],
            'clientDataJSON' => ['required', 'string'],
            'attestationObject' => ['required', 'string'],
            'transports' => ['nullable', 'array'],
            'transports.*' => ['string', 'in:usb,nfc,ble,hybrid,internal'],
        ]);
        $pending = $request->session()->pull('identity.invitation');
        if (! is_array($pending) || ! is_string($pending['challenge_id'] ?? null)
            || ! UserInvitation::looksLikeToken($data['token'])
            || ! hash_equals((string) ($pending['token_hash'] ?? ''), UserInvitation::hashToken($data['token']))) {
            throw ValidationException::withMessages(['passkey' => __('identity.generated.t_2b3724b0f713a540')]);
        }
        $plannedUserId = (string) $pending['user_id'];

        $user = $this->invitations->complete(
            $data['token'],
            $data['name'] ?? null,
            false,
            null,
            function (User $user) use ($challenges, $ceremony, $pending, $data, $plannedUserId): void {
                if ($user->id !== $plannedUserId) {
                    throw ValidationException::withMessages(['passkey' => __('identity.generated.t_2b3724b0f713a540')]);
                }
                $challenge = $challenges->consume((string) $pending['challenge_id'], 'invitation');
                $passkey = $ceremony->verifyEnrollment($challenge, $data);
                UserPasskey::query()->create([
                    'user_id' => $user->id,
                    'name' => trim((string) ($data['passkeyName'] ?? '')) ?: __('identity.security.passkey_messages.default_name'),
                    'credential_id' => $passkey->credentialId,
                    'credential_public_key' => $passkey->credentialPublicKey,
                    'signature_counter' => $passkey->signatureCounter,
                    'transports' => $passkey->transports,
                ]);
            },
            $plannedUserId,
        );

        return $this->signIn($request, $user);
    }

    /**
     * @param  array{invitation: UserInvitation, token: string}  $result
     */
    private function showLinkOnce(array $result, string $status): RedirectResponse
    {
        return redirect()->route('identity.users.index')->with([
            'status' => $status,
            'invitation_url' => $this->invitations->link($result['token']),
            'invitation_expires_at' => $result['invitation']->expires_at->timezone(config('app.timezone'))->format('d-m-Y H:i'),
        ]);
    }

    private function signIn(Request $request, User $user): JsonResponse
    {
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('identity.session_revoked_at', $user->session_revoked_at?->getTimestamp());

        return response()->json([
            'ok' => true,
            'redirect' => $user->hasPermission('users.manage') ? '/admin' : '/admin/assets',
        ]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => __('identity.generated.t_764daa1c66b4c698')], 404)
            ->header('Cache-Control', 'no-store, private');
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
