@extends('layouts.app')
@section('title', 'Beveiliging - FotoArchief')
@section('content')
    <section class="card narrow">
        <p class="eyebrow">Accountbeveiliging</p>
        <h1>Passkeys en herstelcodes</h1>
        <p>Registreer een passkey voor veilig inloggen zonder wachtwoord. Bewaar herstelcodes offline; elke code werkt één keer.</p>
        <label for="passkey-name">Naam voor deze passkey</label>
        <input id="passkey-name" value="{{ php_uname('n') ?: 'Mijn apparaat' }}" maxlength="100">
        <button id="passkey-enroll" type="button">Passkey registreren</button>
        <p id="passkey-status" role="status"></p>
    </section>

    <section class="card">
        <h2>Geregistreerde passkeys</h2>
        @forelse($user->passkeys as $passkey)
            <div class="card">
                <strong>{{ $passkey->name }}</strong>
                <p>Laatst gebruikt: {{ $passkey->last_used_at?->timezone(config('app.timezone'))->format('d-m-Y H:i') ?? 'nog niet' }}</p>
                <form method="post" action="{{ route('identity.passkeys.destroy', $passkey) }}">
                    @csrf
                    @method('delete')
                    <button class="secondary" type="submit">Verwijderen</button>
                </form>
            </div>
        @empty
            <p>Er zijn nog geen passkeys geregistreerd.</p>
        @endforelse
    </section>

    <section class="card narrow">
        <h2>Herstelcodes</h2>
        <p>Beschikbare ongebruikte codes: {{ $user->recoveryCodes->whereNull('used_at')->count() }}. Nieuwe codes vervangen alle bestaande codes.</p>
        <form method="post" action="{{ route('identity.recovery.regenerate') }}">
            @csrf
            <button type="submit">Nieuwe herstelcodes maken</button>
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
                    throw new Error('Deze browser ondersteunt geen passkeys.');
                }
                status.textContent = 'Passkey-registratie gestart...';
                const optionsResponse = await fetch('{{ route('identity.passkeys.options') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } });
                const options = decodeCreateOptions(await optionsResponse.json());
                const credential = await navigator.credentials.create(options);
                const storeResponse = await fetch('{{ route('identity.passkeys.store') }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        name: document.getElementById('passkey-name').value || 'Passkey',
                        id: bufferToBase64(credential.rawId),
                        clientDataJSON: bufferToBase64(credential.response.clientDataJSON),
                        attestationObject: bufferToBase64(credential.response.attestationObject),
                        transports: credential.response.getTransports ? credential.response.getTransports() : null,
                    }),
                });
                if (!storeResponse.ok) {
                    throw new Error('De passkey kon niet worden geregistreerd.');
                }
                window.location.reload();
            } catch (error) {
                status.textContent = error.message || 'De passkey kon niet worden geregistreerd.';
            }
        });
    </script>
@endsection
