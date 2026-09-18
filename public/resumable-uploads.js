'use strict';
(() => {
    const form = document.getElementById('resumable-upload');
    if (!form) return;
    const input = document.getElementById('resumable-files');
    const result = document.getElementById('resumable-result');
    const progress = document.getElementById('resumable-progress');
    const button = form.querySelector('button');
    const messages = JSON.parse(form.dataset.messages);
    const token = form.querySelector('[name="_token"]').value;
    class UploadError extends Error {}
    let busy = false;
    async function request(url, body) {
        const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': token };
        if (body && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
        const response = await fetch(url, {
            method: body ? 'POST' : 'GET', headers, credentials: 'same-origin',
            body: body instanceof FormData ? body : body ? JSON.stringify(body) : undefined,
            signal: AbortSignal.timeout(60000),
        });
        if (!response.ok) {
            let message = messages.network;
            try {
                const error = await response.json();
                if (response.status === 422 && error.errors) message = Object.values(error.errors).flat().join(' ');
                else if (response.status === 409 && error.message) message = error.message;
            } catch { /* Keep an explicit network/session error for non-JSON proxy responses. */ }
            throw new UploadError(message);
        }
        return response.json();
    }
    async function digest(bytes) {
        return Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)), byte => byte.toString(16).padStart(2, '0')).join('');
    }
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy) return;
        busy = true;
        button.disabled = true;
        input.disabled = true;
        progress.value = 0;
        try {
            if (!globalThis.crypto?.subtle || !crypto.randomUUID) throw new UploadError(messages.unsupported);
            const files = Array.from(input.files);
            if (!files.length || files.length > Number(form.dataset.maxFiles)
                || files.reduce((sum, file) => sum + file.size, 0) > 1073741824
                || files.some(file => !/\.(jpe?g|png|webp)$/i.test(file.name)
                    || (file.type && !['image/jpeg', 'image/png', 'image/webp'].includes(file.type))
                    || file.size < 1 || file.size > Number(form.dataset.maxBytes))) throw new UploadError(messages.invalid_selection);
            result.textContent = messages.checking;
            const manifest = [];
            const byHash = new Map();
            for (const file of files) {
                const sha256 = await digest(await file.arrayBuffer());
                if (byHash.has(sha256)) throw new UploadError(messages.invalid_selection);
                byHash.set(sha256, file);
                manifest.push({ filename: file.name, byte_size: file.size, sha256 });
            }
            let url = form.dataset.endpoint;
            if (form.dataset.existing !== '1') {
                const receipt = 'fotoarchief-upload-' + await digest(new TextEncoder().encode(JSON.stringify(manifest)));
                let clientKey = localStorage.getItem(receipt);
                if (!clientKey) {
                    clientKey = crypto.randomUUID();
                    localStorage.setItem(receipt, clientKey);
                }
                const created = await request(url, { client_key: clientKey, files: manifest });
                // A lost create response can be retried with this persisted idempotency key.
                url = created.url;
                form.dataset.endpoint = url;
                form.dataset.existing = '1';
                window.history.replaceState(null, '', url);
            }
            const status = await request(url);
            if (status.closed) throw new UploadError(messages.closed);
            if (manifest.some(file => !status.items.some(item => item.sha256 === file.sha256 && item.byte_size === file.byte_size))) throw new UploadError(messages.mismatch);
            result.textContent = messages.sending;
            let sent = 0;
            const total = files.reduce((sum, file) => sum + file.size, 0);
            for (const item of status.items) {
                const file = byHash.get(item.sha256);
                if (!file) continue;
                if (!['receiving', 'failed'].includes(item.transfer_status)) { sent += file.size; continue; }
                const chunks = Math.ceil(file.size / status.chunk_bytes);
                for (let position = 0; position < chunks; position++) {
                    const part = file.slice(position * status.chunk_bytes, (position + 1) * status.chunk_bytes);
                    if (!item.received.includes(position)) {
                        const body = new FormData();
                        body.append('position', String(position));
                        body.append('chunk', part, 'chunk.bin');
                        await request(url + '/items/' + item.id + '/chunk', body);
                    }
                    sent += part.size;
                    progress.value = 100 * sent / total;
                }
                await request(url + '/items/' + item.id + '/finalize', {});
            }
            result.textContent = messages.received;
            window.location.reload();
        } catch (error) {
            result.textContent = error instanceof UploadError ? error.message : messages.network;
        } finally {
            busy = false;
            button.disabled = false;
            input.disabled = false;
        }
    });
})();
