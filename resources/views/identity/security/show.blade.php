@extends('layouts.app')
@section('title', __('identity.security.title'))
@section('content')
    <section class="card narrow">
        <p class="eyebrow">{{ __('identity.security.eyebrow') }}</p>
        <h1>{{ __('identity.security.heading') }}</h1>
        <p>{{ __('identity.security.intro') }}</p>
        <label for="passkey-name">{{ __('identity.security.passkey_name') }}</label>
        <input id="passkey-name" value="{{ php_uname('n') ?: __('identity.security.default_passkey_name') }}" maxlength="100">
        <button id="passkey-enroll" type="button">{{ __('identity.security.register_passkey') }}</button>
        <p id="passkey-status" role="status"></p>
    </section>

    <section class="card">
        <h2>{{ __('identity.security.registered_passkeys') }}</h2>
        @forelse($user->passkeys as $passkey)
            <div class="card">
                <strong>{{ $passkey->name }}</strong>
                <p>{{ __('identity.security.last_used', ['date' => $passkey->last_used_at?->timezone(config('app.timezone'))->format('d-m-Y H:i') ?? __('identity.security.never_used')]) }}</p>
                <form method="post" action="{{ route('identity.passkeys.destroy', $passkey) }}">
                    @csrf
                    @method('delete')
                    <button class="secondary" type="submit">{{ __('identity.security.remove') }}</button>
                </form>
            </div>
        @empty
            <p>{{ __('identity.security.no_passkeys') }}</p>
        @endforelse
    </section>

    <section class="card narrow">
        <h2>{{ __('identity.security.recovery_codes') }}</h2>
        <p>{{ __('identity.security.available_recovery_codes', ['count' => $user->recoveryCodes->whereNull('used_at')->count()]) }}</p>
        <form method="post" action="{{ route('identity.recovery.regenerate') }}">
            @csrf
            <button type="submit">{{ __('identity.security.regenerate_recovery_codes') }}</button>
        </form>
    </section>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const status = document.getElementById('passkey-status');
        const base64UrlToBuffer = (value) => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((value.length + 3) % 4)), char => char.charCodeAt(0)).buffer;
        const bufferToBase64 = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer)));
        const decodeCreateOptions = (options) => {
            options.publicKey.challenge = base64UrlToBuffer(options.publicKey.challenge);
            options.publicKey.user.id = base64UrlToBuffer(options.publicKey.user.id);
            options.publicKey.excludeCredentials = (options.publicKey.excludeCredentials || []).map((credential) => ({ ...credential, id: base64UrlToBuffer(credential.id) }));
            return options;
        };
        document.getElementById('passkey-enroll').addEventListener('click', async () => {
            try {
                if (!navigator.credentials) {
                    throw new Error(@json(__('identity.security.passkey_messages.unsupported')));
                }
                status.textContent = @json(__('identity.security.passkey_messages.started'));
                const optionsResponse = await fetch('{{ route('identity.passkeys.options') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } });
                const options = decodeCreateOptions(await optionsResponse.json());
                const credential = await navigator.credentials.create(options);
                const storeResponse = await fetch('{{ route('identity.passkeys.store') }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        name: document.getElementById('passkey-name').value || @json(__('identity.security.passkey_messages.default_name')),
                        id: bufferToBase64(credential.rawId),
                        clientDataJSON: bufferToBase64(credential.response.clientDataJSON),
                        attestationObject: bufferToBase64(credential.response.attestationObject),
                        transports: credential.response.getTransports ? credential.response.getTransports() : null,
                    }),
                });
                if (!storeResponse.ok) {
                    throw new Error(@json(__('identity.security.passkey_messages.failed')));
                }
                window.location.reload();
            } catch (error) {
                status.textContent = error.message || @json(__('identity.security.passkey_messages.failed'));
            }
        });
    </script>
@endsection
