@php
    // Passkeys are bound to the origin in APP_URL. A browser on another address, on plain
    // HTTP, or behind a proxy the app does not trust fails with a bare "Failed to fetch";
    // these checks name the real cause before the ceremony starts.
    try {
        $passkeyOrigin = app(\App\Modules\Identity\Services\WebAuthnRelyingParty::class)->origin();
        $passkeyProblem = null;
    } catch (\RuntimeException $exception) {
        $passkeyOrigin = null;
        $passkeyProblem = $exception->getMessage();
    }
@endphp
@if($passkeyProblem !== null)
    <p role="alert" data-passkey-problem>{{ $passkeyProblem }}</p>
@endif
<script>
    const passkeyEnvironment = {
        origin: @json($passkeyOrigin),
        problem: @json($passkeyProblem),
        messages: @json(__('identity.passkey_environment')),
    };
    const assertPasskeyEnvironment = () => {
        if (passkeyEnvironment.problem) {
            throw new Error(passkeyEnvironment.problem);
        }
        if (!window.isSecureContext) {
            throw new Error(passkeyEnvironment.messages.insecure);
        }
        if (passkeyEnvironment.origin && window.location.origin !== passkeyEnvironment.origin) {
            throw new Error(passkeyEnvironment.messages.wrong_origin
                .replaceAll(':configured', passkeyEnvironment.origin)
                .replaceAll(':current', window.location.origin));
        }
    };
    const passkeyFetch = async (url, init) => {
        try {
            return await fetch(url, { credentials: 'same-origin', ...init });
        } catch (error) {
            throw new Error(passkeyEnvironment.messages.unreachable);
        }
    };
</script>
