@extends('layouts.app')
@section('title', 'Inloggen - FotoArchief')
@section('content')
    <section class="card narrow">
        <p class="eyebrow">Beheeromgeving</p>
        <h1>Welkom terug</h1>
        <p>Log in met je passkey, herstelcode of tijdelijke wachtwoord.</p>
        <button id="passkey-login" type="button">Inloggen met passkey</button>
        <p id="passkey-login-status" role="status"></p>
        <form method="post" action="/login">
            @csrf
            <label for="email">E-mailadres</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username">
            <label for="password">Wachtwoord</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
            <button type="submit">Inloggen</button>
        </form>
        <h2>Herstelcode gebruiken</h2>
        <p>Gebruik alleen een herstelcode als je geen passkey of wachtwoord kunt gebruiken. De code wordt daarna ongeldig.</p>
        <form method="post" action="{{ route('identity.recovery.login') }}">
            @csrf
            <label for="recovery-email">E-mailadres</label>
            <input id="recovery-email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username">
            <label for="code">Herstelcode</label>
            <input id="code" name="code" required autocomplete="one-time-code">
            <button type="submit">Inloggen met herstelcode</button>
        </form>
    </section>
    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const status = document.getElementById('passkey-login-status');
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
                    throw new Error('Deze browser ondersteunt geen passkeys.');
                }
                status.textContent = 'Passkey-aanvraag gestart...';
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
                    throw new Error('De passkey is niet geaccepteerd.');
                }
                window.location.href = result.redirect;
            } catch (error) {
                status.textContent = error.message || 'De passkey is niet geaccepteerd.';
            }
        });
    </script>
@endsection
