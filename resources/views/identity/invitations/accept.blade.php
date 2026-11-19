@extends('layouts.app')
@section('title', __('identity.invitations.accept.title'))
@section('content')
    {{-- App-Guidance user-management.md: the token is in the fragment (#invite=...), read by the
         script below, sent only in a header or a JSON body, and removed from the address bar. --}}
    <section class="card narrow" data-invitation-page>
        <p class="eyebrow">{{ __('identity.invitations.accept.eyebrow') }}</p>
        <h1 data-invitation-heading>{{ __('identity.invitations.accept.heading') }}</h1>
        <noscript><p role="alert">{{ __('identity.invitations.accept.needs_script') }}</p></noscript>
        <p data-invitation-loading role="status">{{ __('identity.invitations.accept.loading') }}</p>
        <p data-invitation-invalid role="alert" hidden>{{ __('identity.invitations.accept.invalid') }}</p>

        <div data-invitation-ready hidden>
            <p data-invitation-summary></p>
            <p>{{ __('identity.invitations.accept.own_credentials') }}</p>

            <form data-invitation-password novalidate>
                <label for="invitation-name">{{ __('identity.common.name') }}</label>
                <input id="invitation-name" name="name" maxlength="255" autocomplete="name">
                <label for="invitation-email">{{ __('identity.common.email') }}</label>
                <input id="invitation-email" type="email" readonly autocomplete="username">
                <h2>{{ __('identity.invitations.accept.password_heading') }}</h2>
                <label for="password">{{ __('identity.invitations.accept.password') }}</label>
                <input id="password" name="password" type="password" required minlength="{{ $passwordMin }}" maxlength="128" autocomplete="new-password">
                <label for="password_confirmation">{{ __('identity.invitations.accept.password_confirmation') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required minlength="{{ $passwordMin }}" maxlength="128" autocomplete="new-password">
                <button type="submit">{{ __('identity.invitations.accept.submit') }}</button>
            </form>

            <h2>{{ __('identity.invitations.accept.passkey_heading') }}</h2>
            <p>{{ __('identity.invitations.accept.passkey_intro') }}</p>
            <button type="button" class="secondary" data-invitation-passkey>{{ __('identity.invitations.accept.passkey_button') }}</button>
            @include('identity.partials.passkey-environment')
            <p data-invitation-status role="status"></p>
        </div>
    </section>

    <script>
        (function () {
            'use strict';
            const storageKey = 'fotoarchief.invitation';
            const messages = @json(__('identity.invitations.accept.messages'));
            const urls = {
                info: @json(route('identity.invitations.info', [], false)),
                password: @json(route('identity.invitations.password', [], false)),
                passkeyOptions: @json(route('identity.invitations.passkey.options', [], false)),
                passkeyStore: @json(route('identity.invitations.passkey.store', [], false)),
            };
            const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            const page = document.querySelector('[data-invitation-page]');
            const loading = page.querySelector('[data-invitation-loading]');
            const invalid = page.querySelector('[data-invitation-invalid]');
            const ready = page.querySelector('[data-invitation-ready]');
            const status = page.querySelector('[data-invitation-status]');
            const passkeyButton = page.querySelector('[data-invitation-passkey]');
            const nameInput = page.querySelector('#invitation-name');

            const store = (value) => { try { value === null ? sessionStorage.removeItem(storageKey) : sessionStorage.setItem(storageKey, value); } catch (error) { /* storage can be absent */ } };
            const stored = () => { try { return sessionStorage.getItem(storageKey); } catch (error) { return null; } };

            // Read the token from the fragment, keep it for a reload in this tab only, and take it
            // out of the address bar so the history holds no live token.
            const match = /(?:^#|[#&])invite=([A-Za-z0-9_-]{32,128})/.exec(window.location.hash);
            if (match) {
                store(match[1]);
                history.replaceState(null, '', window.location.pathname);
            }
            const token = match ? match[1] : stored();

            const showInvalid = () => { loading.hidden = true; ready.hidden = true; invalid.hidden = false; store(null); };
            const firstError = async (response) => {
                try {
                    const body = await response.json();
                    if (body.errors) {
                        const first = Object.values(body.errors)[0];
                        return Array.isArray(first) ? first[0] : String(first);
                    }
                    return body.message || messages.failed;
                } catch (error) {
                    return messages.failed;
                }
            };
            const finish = async (response) => {
                if (response.status === 404) {
                    showInvalid();
                    return;
                }
                if (!response.ok) {
                    throw new Error(await firstError(response));
                }
                const body = await response.json();
                store(null);
                window.location.assign(body.redirect || '/admin');
            };
            const post = (url, payload) => fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ token: token, ...payload }),
            });

            if (!token || !window.fetch) {
                showInvalid();
                return;
            }

            fetch(urls.info, { credentials: 'same-origin', headers: { 'X-Invite-Token': token, 'Accept': 'application/json' } })
                .then((response) => response.ok ? response.json() : null)
                .then((info) => {
                    if (!info) {
                        showInvalid();
                        return;
                    }
                    const isReset = info.kind === 'reset';
                    page.querySelector('[data-invitation-heading]').textContent = isReset ? messages.reset_heading : messages.new_heading;
                    page.querySelector('[data-invitation-summary]').textContent = (isReset ? messages.reset_summary : messages.new_summary)
                        .replace(':email', info.email)
                        .replace(':inviter', info.invitedBy || messages.unknown_inviter)
                        .replace(':expires_at', info.expiresAtLocal);
                    nameInput.value = info.name || '';
                    nameInput.readOnly = isReset;
                    page.querySelector('#invitation-email').value = info.email;
                    loading.hidden = true;
                    ready.hidden = false;
                    try {
                        assertPasskeyEnvironment();
                        if (!window.PublicKeyCredential || !navigator.credentials) {
                            throw new Error(messages.passkey_unsupported);
                        }
                    } catch (error) {
                        passkeyButton.disabled = true;
                        passkeyButton.title = error.message;
                    }
                })
                .catch(showInvalid);

            page.querySelector('[data-invitation-password]').addEventListener('submit', async (event) => {
                event.preventDefault();
                const form = event.currentTarget;
                status.textContent = messages.saving;
                try {
                    await finish(await post(urls.password, {
                        name: nameInput.readOnly ? null : nameInput.value,
                        password: form.password.value,
                        password_confirmation: form.password_confirmation.value,
                    }));
                } catch (error) {
                    status.textContent = error.message || messages.failed;
                }
            });

            const base64UrlToBuffer = (value) => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((value.length + 3) % 4)), char => char.charCodeAt(0)).buffer;
            const bufferToBase64 = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer)));
            passkeyButton.addEventListener('click', async () => {
                try {
                    assertPasskeyEnvironment();
                    status.textContent = messages.passkey_started;
                    const name = nameInput.readOnly ? null : nameInput.value;
                    const optionsResponse = await passkeyFetch(urls.passkeyOptions, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ token: token, name: name }),
                    });
                    if (optionsResponse.status === 404) {
                        showInvalid();
                        return;
                    }
                    if (!optionsResponse.ok) {
                        throw new Error(await firstError(optionsResponse));
                    }
                    const options = await optionsResponse.json();
                    options.publicKey.challenge = base64UrlToBuffer(options.publicKey.challenge);
                    options.publicKey.user.id = base64UrlToBuffer(options.publicKey.user.id);
                    options.publicKey.excludeCredentials = (options.publicKey.excludeCredentials || []).map((credential) => ({ ...credential, id: base64UrlToBuffer(credential.id) }));
                    const credential = await navigator.credentials.create(options);
                    await finish(await post(urls.passkeyStore, {
                        name: name,
                        passkeyName: messages.passkey_default_name,
                        id: bufferToBase64(credential.rawId),
                        clientDataJSON: bufferToBase64(credential.response.clientDataJSON),
                        attestationObject: bufferToBase64(credential.response.attestationObject),
                        transports: credential.response.getTransports ? credential.response.getTransports() : null,
                    }));
                } catch (error) {
                    status.textContent = error.message || messages.failed;
                }
            });
        }());
    </script>
@endsection
