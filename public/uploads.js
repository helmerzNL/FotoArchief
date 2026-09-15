'use strict';
(() => {
    const form = document.getElementById('upload-form');
    if (!form) return;
    const input = document.getElementById('files');
    const zone = document.getElementById('drop-zone');
    const results = document.getElementById('upload-results');
    const submit = document.getElementById('upload-submit');
    let busy = false;

    for (const eventName of ['dragover', 'drop']) {
        zone.addEventListener(eventName, event => {
            event.preventDefault();
            if (eventName === 'drop' && !busy && event.dataTransfer) {
                input.files = event.dataTransfer.files;
            }
        });
    }
    function send(file, row) {
        return new Promise(resolve => {
            row.replaceChildren();
            const name = document.createElement('span');
            name.textContent = file.name + ': ';
            const progress = document.createElement('progress');
            progress.max = 100;
            progress.value = 0;
            progress.setAttribute('aria-label', 'Uploadvoortgang ' + file.name);
            const state = document.createElement('span');
            state.textContent = 'Versturen...';
            row.append(name, progress, state);
            const data = new FormData();
            data.append('_token', form.elements._token.value);
            data.append('files[]', file);
            const xhr = new XMLHttpRequest();
            xhr.open('POST', form.action);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.timeout = 300000;
            xhr.upload.onprogress = event => {
                if (event.lengthComputable) progress.value = Math.round(100 * event.loaded / event.total);
            };
            const failure = message => {
                state.textContent = message;
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.textContent = 'Opnieuw versturen';
                retry.addEventListener('click', async () => {
                    if (busy) return;
                    busy = true;
                    submit.disabled = true;
                    await send(file, row);
                    busy = false;
                    submit.disabled = false;
                });
                row.append(retry);
                resolve();
            };
            xhr.onload = () => {
                let body;
                try { body = JSON.parse(xhr.responseText); } catch {
                    failure('Geen geldig antwoord (HTTP ' + xhr.status + '). Controleer sessie en hostlimieten. Controleer het archief voor opnieuw versturen.');
                    return;
                }
                const result = body.results?.[0];
                if (xhr.status === 201 && result?.ok) {
                    progress.value = 100;
                    state.textContent = 'Ontvangen, verwerking ingepland. ';
                    const link = document.createElement('a');
                    link.href = result.url;
                    link.textContent = 'Bekijk verwerking';
                    row.append(link);
                    resolve();
                } else {
                    failure(result?.error || body.message || 'Upload geweigerd (HTTP ' + xhr.status + ').');
                }
            };
            xhr.onerror = () => failure('Netwerkfout. Controleer het archief voordat je opnieuw verstuurt.');
            xhr.ontimeout = () => failure('Geen antwoord binnen 300 seconden. Controleer het archief voordat je opnieuw verstuurt.');
            xhr.send(data);
        });
    }
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy) return;
        const files = Array.from(input.files);
        if (files.length > Number(form.dataset.maxFiles)) {
            results.textContent = 'Selecteer maximaal ' + form.dataset.maxFiles + ' bestanden.';
            return;
        }
        busy = true;
        submit.disabled = true;
        input.disabled = true;
        results.replaceChildren();
        for (const file of files) {
            const row = document.createElement('li');
            results.append(row);
            if (file.size === 0 || file.size > Number(form.dataset.maxBytes)) {
                row.textContent = file.name + ': geweigerd; leeg bestand of bestand te groot.';
                continue;
            }
            await send(file, row);
        }
        input.disabled = false;
        submit.disabled = false;
        busy = false;
    });
})();
