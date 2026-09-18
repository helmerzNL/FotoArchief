@extends('layouts.app')
@section('title', __('auth.login.title'))
@section('content')
    <section class="card narrow auth-card">
        <p class="eyebrow">{{ __('auth.login.area') }}</p>
        <h1>{{ __('auth.login.heading') }}</h1>
        <p>{{ __('auth.login.intro') }}</p>
        <button id="passkey-login" type="button">{{ __('auth.login.passkey_button') }}</button>
        <p id="passkey-login-status" role="status"></p>
        <form method="post" action="/login">
            @csrf
            <label for="email">{{ __('auth.login.email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username">
            <label for="password">{{ __('auth.login.password') }}</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
            <button type="submit">{{ __('auth.login.submit') }}</button>
        </form>
        <h2>{{ __('auth.login.recovery_heading') }}</h2>
        <p>{{ __('auth.login.recovery_intro') }}</p>
        <form method="post" action="{{ route('identity.recovery.login') }}">
            @csrf
            <label for="recovery-email">{{ __('auth.login.email') }}</label>
            <input id="recovery-email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username">
            <label for="code">{{ __('auth.login.recovery_code') }}</label>
            <input id="code" name="code" required autocomplete="one-time-code">
            <button type="submit">{{ __('auth.login.recovery_submit') }}</button>
        </form>
    </section>
    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const status = document.getElementById('passkey-login-status');
        const passkeyMessages = @json(__('auth.login.passkey_messages'));
        const base64UrlToBuffer = (value) => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((value.length + 3) % 4)), char => char.charCodeAt(0)).buffer;
        const bufferToBase64 = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer)));
        const decodeOptions = (options) => {
            options.publicKey.challenge = base64UrlToBuffer(options.publicKey.challenge);
            if (options.publicKey.allowCredentials) {
                options.publicKey.allowCredentials = options.publicKey.allowCredentials.map((credential) => ({ ...credential, id: base64UrlToBuffer(credential.id) }));
            }
            return options;
        };
        document.getElementById('passkey-login').addEventListener('click', async () => {
            try {
                if (!navigator.credentials) {
                    throw new Error(passkeyMessages.unsupported);
                }
                status.textContent = passkeyMessages.started;
                const optionsResponse = await fetch('{{ route('identity.passkeys.login.options') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } });
                const options = decodeOptions(await optionsResponse.json());
                const credential = await navigator.credentials.get(options);
                const response = await fetch('{{ route('identity.passkeys.login') }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        id: bufferToBase64(credential.rawId),
                        clientDataJSON: bufferToBase64(credential.response.clientDataJSON),
                        authenticatorData: bufferToBase64(credential.response.authenticatorData),
                        signature: bufferToBase64(credential.response.signature),
                        userHandle: credential.response.userHandle ? bufferToBase64(credential.response.userHandle) : null,
                    }),
                });
                const result = await response.json();
                if (!response.ok || !result.ok) {
                    throw new Error(passkeyMessages.rejected);
                }
                window.location.href = result.redirect;
            } catch (error) {
                status.textContent = error.message || passkeyMessages.rejected;
            }
        });
    </script>
@endsection
