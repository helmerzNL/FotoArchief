@extends('layouts.app')
@section('title', __('operations.backup.title'))
@section('content')
    @php
        $formatBytes = fn (int $bytes): string => number_format($bytes / 1048576, 1, ',', '.').' MB';
        $tz = config('app.timezone');
    @endphp
    <h1>{{ __('operations.backup.heading') }}</h1>
    <p class="intro">{{ __('operations.backup.intro') }}</p>

    <section class="card" data-backup-page>
        <h2>{{ __('operations.backup.make_heading') }}</h2>
        <p>{{ __('operations.backup.contents') }}</p>
        <p>{{ __('operations.backup.never') }}</p>
        <div class="actions">
            <button type="button" data-backup-action="export" data-with-files="1">{{ __('operations.backup.make_with_files') }}</button>
            <button type="button" class="secondary" data-backup-action="export" data-with-files="0">{{ __('operations.backup.make_metadata') }}</button>
        </div>
        <p>{{ __('operations.backup.not_a_server_backup') }}</p>

        <h2>{{ __('operations.backup.restore_heading') }}</h2>
        <p>{{ __('operations.backup.restore_intro', ['max' => $formatBytes($maxUploadBytes)]) }}</p>
        <label for="backup-file">{{ __('operations.backup.choose_file') }}</label>
        <input id="backup-file" type="file" accept=".zip,.json,application/zip,application/json" data-backup-file>
        <div class="actions">
            <button type="button" class="secondary" data-backup-action="upload">{{ __('operations.backup.restore_button') }}</button>
        </div>

        {{-- One progress block for everything on this page (App-Guidance backup-restore.md). --}}
        <div data-backup-progress role="status" aria-live="polite" hidden>
            <p><strong data-backup-label></strong></p>
            <progress data-backup-bar></progress>
            <p data-backup-note></p>
        </div>
        <div data-backup-outcome hidden>
            <p data-backup-outcome-text></p>
            <p data-backup-outcome-detail></p>
            <div class="actions">
                <a class="button" data-backup-download hidden>{{ __('operations.backup.download') }}</a>
                <button type="button" class="secondary" data-backup-dismiss>{{ __('operations.backup.close') }}</button>
            </div>
        </div>
    </section>

    <section class="card" data-backup-automatic>
        <h2>{{ __('operations.backup.auto_heading') }}</h2>
        @if($automatic['enabled'])
            <p>{{ __('operations.backup.auto_on', ['hours' => $automatic['interval_hours'], 'keep' => $automatic['keep']]) }}
                {{ $automatic['with_files'] ? __('operations.backup.auto_with_files') : __('operations.backup.auto_metadata') }}</p>
            <p>
                @if($automatic['next_at'] === null || $automatic['next_at']->isPast())
                    {{ __('operations.backup.auto_next_soon') }}
                @else
                    {{ __('operations.backup.auto_next', ['at' => $automatic['next_at']->timezone($tz)->format('d-m-Y H:i')]) }}
                @endif
            </p>
        @else
            <p>{{ __('operations.backup.auto_off') }}</p>
        @endif
        <p>{{ __('operations.backup.auto_disk') }}</p>
        @if($automatic['failure'] !== null)
            <p role="alert" class="errors">{{ __('operations.backup.auto_failed', ['at' => $automatic['failure']['at']?->timezone($tz)->format('d-m-Y H:i'), 'message' => $automatic['failure']['message']]) }}</p>
        @endif
        @if($automatic['backups'] === [])
            <p>{{ __('operations.backup.auto_none') }}</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th scope="col">{{ __('operations.backup.auto_when') }}</th>
                        <th scope="col">{{ __('operations.backup.auto_size') }}</th>
                        <th scope="col"><span class="sr-only">{{ __('operations.backup.auto_actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($automatic['backups'] as $backup)
                        <tr>
                            <td>{{ $backup['created_at']->timezone($tz)->format('d-m-Y H:i') }}</td>
                            <td>{{ $formatBytes($backup['bytes']) }}</td>
                            <td class="actions">
                                <a class="button secondary" href="{{ route('admin.operations.backup.auto.download', $backup['name']) }}" data-backup-auto-download>{{ __('operations.backup.download') }}</a>
                                <button type="button" class="secondary" data-backup-action="auto-restore" data-name="{{ $backup['name'] }}">{{ __('operations.backup.restore_button') }}</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <dialog data-backup-confirm>
        <form method="dialog">
            <h2>{{ __('operations.backup.confirm_heading') }}</h2>
            <p data-backup-confirm-made></p>
            <ul data-backup-confirm-counts></ul>
            <p>{{ __('operations.backup.confirm_replaces') }}</p>
            <p>{{ __('operations.backup.confirm_stays') }}</p>
            <p><strong>{{ __('operations.backup.confirm_undo') }}</strong></p>
            <p>{{ __('operations.backup.confirm_leave') }}</p>
            <div class="actions">
                <button value="cancel" class="secondary">{{ __('operations.backup.cancel') }}</button>
                <button value="confirm" data-backup-confirm-go>{{ __('operations.backup.confirm_go') }}</button>
            </div>
        </form>
    </dialog>

    <script>
        (function () {
            'use strict';
            const t = @json(__('operations.backup.js'));
            const sectionNames = @json(__('operations.backup.sections'));
            const urls = {
                export: @json(route('admin.operations.backup.export', [], false)),
                stage: @json(route('admin.operations.backup.stage', [], false)),
                restore: @json(route('admin.operations.backup.restore', [], false)),
                latest: @json(route('admin.operations.backup.latest', [], false)),
                jobs: @json(url('/admin/operations/backup/jobs')),
                autoBase: @json(url('/admin/operations/backup/auto')),
            };
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const page = document.querySelector('[data-backup-page]');
            const progress = page.querySelector('[data-backup-progress]');
            const label = page.querySelector('[data-backup-label]');
            const bar = page.querySelector('[data-backup-bar]');
            const note = page.querySelector('[data-backup-note]');
            const outcome = page.querySelector('[data-backup-outcome]');
            const outcomeText = page.querySelector('[data-backup-outcome-text]');
            const outcomeDetail = page.querySelector('[data-backup-outcome-detail]');
            const download = page.querySelector('[data-backup-download]');
            const dismissButton = page.querySelector('[data-backup-dismiss]');
            const dialog = document.querySelector('[data-backup-confirm]');
            const number = new Intl.NumberFormat(document.documentElement.lang || 'nl', { maximumFractionDigits: 1 });
            const mb = (bytes) => number.format(bytes / 1048576) + ' MB';
            const seconds = (value) => number.format(value || 0) + ' s';
            let polling = null;
            let shownJob = null;

            const buttons = () => document.querySelectorAll('[data-backup-action], [data-backup-auto-download]');
            const setBusy = (busy) => buttons().forEach((button) => {
                if (button.tagName === 'A') {
                    button.toggleAttribute('aria-disabled', busy);
                    button.style.pointerEvents = busy ? 'none' : '';
                } else {
                    button.disabled = busy;
                }
            });
            const show = (text, done, total, extra) => {
                outcome.hidden = true;
                progress.hidden = false;
                label.textContent = text;
                if (total > 0) {
                    bar.max = total;
                    bar.value = Math.min(done, total);
                } else {
                    bar.removeAttribute('value');
                    bar.removeAttribute('max');
                }
                note.textContent = extra || '';
                progress.scrollIntoView({ block: 'nearest' });
            };
            const request = async (method, url, body, headers) => {
                const response = await fetch(url, {
                    method: method,
                    credentials: 'same-origin',
                    headers: Object.assign({ 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body !== undefined ? { 'Content-Type': 'application/json' } : {}, headers || {}),
                    body: body !== undefined ? JSON.stringify(body) : undefined,
                });
                let json = null;
                try { json = await response.json(); } catch (error) { json = null; }
                if (!response.ok) {
                    throw new Error((json && json.message) || t.failed);
                }
                return json;
            };
            const fail = (message) => {
                progress.hidden = true;
                outcome.hidden = false;
                outcomeText.textContent = message;
                outcomeDetail.textContent = '';
                download.hidden = true;
                dismissButton.onclick = () => { outcome.hidden = true; setBusy(false); };
                setBusy(false);
            };

            // ----- following the server job -----
            const leave = t.can_leave;
            const render = (job) => {
                if (!job) {
                    setBusy(false);
                    return false;
                }
                if (job.status === 'running') {
                    setBusy(true);
                    const counted = job.steps > 0 ? t.count.replace(':step', job.step).replace(':steps', job.steps) + ' · ' : '';
                    if (job.kind === 'export') {
                        if (job.phase === 'files') {
                            show(t.export_files, job.step, job.steps, counted + leave);
                        } else {
                            show(job.includesFiles ? t.export_collecting_files : t.export_collecting, 0, 0, leave);
                        }
                    } else if (job.phase === 'checking') {
                        show(t.restore_checking_file, 0, 0, leave);
                    } else if (job.phase === 'files') {
                        show(job.includesFiles ? t.restore_files : t.restore_files_in_storage, job.step, job.steps, counted + leave);
                    } else {
                        show(t.restore_replacing, job.step > 0 ? job.step : 0, job.step > 0 ? job.steps : 0, (job.step > 0 ? counted : '') + leave);
                    }
                    return true;
                }
                if (shownJob === job.id) {
                    return false;
                }
                shownJob = job.id;
                progress.hidden = true;
                outcome.hidden = false;
                download.hidden = true;
                if (job.status === 'done' && job.kind === 'export') {
                    outcomeText.textContent = t.export_done.replace(':size', mb(job.resultBytes || 0));
                    outcomeDetail.textContent = t.export_timing.replace(':server', seconds(job.checkingSeconds));
                    if (job.downloadUrl) {
                        download.hidden = false;
                        download.href = job.downloadUrl;
                        download.setAttribute('download', job.resultName || '');
                    }
                } else if (job.status === 'done') {
                    outcomeText.textContent = t.restore_done;
                    outcomeDetail.textContent = t.restore_timing.replace(':checking', seconds(job.checkingSeconds)).replace(':replacing', seconds(job.replacingSeconds));
                } else {
                    outcomeText.textContent = job.error || t.failed;
                    outcomeDetail.textContent = '';
                }
                dismissButton.onclick = async () => {
                    try {
                        await request('DELETE', urls.jobs + '/' + job.id);
                    } catch (error) {
                        // Do not reload: a reload would show the same finished job again.
                        outcome.hidden = true;
                        setBusy(false);
                        return;
                    }
                    if (job.kind === 'restore' && job.status === 'done') {
                        window.location.reload();
                        return;
                    }
                    outcome.hidden = true;
                    setBusy(false);
                };
                setBusy(false);
                return false;
            };
            const poll = async () => {
                clearTimeout(polling);
                let running = true;
                try {
                    const answer = await request('GET', urls.latest);
                    running = render(answer.job);
                } catch (error) {
                    // A dropped connection does not stop the job; ask again.
                }
                if (running) {
                    polling = setTimeout(poll, 1000);
                }
            };

            // ----- making a backup -----
            document.querySelectorAll('[data-backup-action="export"]').forEach((button) => button.addEventListener('click', async () => {
                setBusy(true);
                show(t.starting, 0, 0, '');
                try {
                    await request('POST', urls.export, { with_files: button.dataset.withFiles === '1' });
                    poll();
                } catch (error) {
                    fail(error.message);
                }
            }));

            // ----- restoring -----
            const confirmRestore = (staged) => new Promise((resolve) => {
                const summary = staged.summary || {};
                const made = summary.created_at ? new Date(summary.created_at).toLocaleString(document.documentElement.lang || 'nl') : '?';
                dialog.querySelector('[data-backup-confirm-made]').textContent = t.confirm_made
                    .replace(':date', made)
                    .replace(':version', summary.app_version || '?')
                    .replace(':kind', summary.includes_files ? t.kind_files : t.kind_metadata);
                const list = dialog.querySelector('[data-backup-confirm-counts]');
                list.textContent = '';
                Object.entries(summary.counts || {}).forEach(([section, count]) => {
                    if (count > 0 && sectionNames[section]) {
                        const item = document.createElement('li');
                        item.textContent = sectionNames[section] + ': ' + number.format(count);
                        list.appendChild(item);
                    }
                });
                dialog.onclose = () => resolve(dialog.returnValue === 'confirm');
                dialog.showModal();
            });
            const afterStage = async (staged) => {
                progress.hidden = true;
                if (!(await confirmRestore(staged))) {
                    await request('DELETE', urls.stage + '/' + staged.token).catch(() => null);
                    setBusy(false);
                    return;
                }
                show(t.starting, 0, 0, leave);
                await request('POST', urls.restore, { token: staged.token });
                poll();
            };
            const putChunk = (url, blob, onProgress) => new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.open('PUT', url);
                xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('Content-Type', 'application/octet-stream');
                xhr.upload.onprogress = (event) => onProgress(event.loaded);
                xhr.onload = () => {
                    let json = null;
                    try { json = JSON.parse(xhr.responseText); } catch (error) { json = null; }
                    if (xhr.status >= 200 && xhr.status < 300 && json) {
                        resolve(json);
                    } else {
                        reject(new Error((json && json.message) || t.failed));
                    }
                };
                xhr.onerror = () => reject(new Error(t.connection));
                xhr.send(blob);
            });
            page.querySelector('[data-backup-action="upload"]').addEventListener('click', async () => {
                const file = page.querySelector('[data-backup-file]').files[0];
                if (!file) {
                    fail(t.choose_first);
                    return;
                }
                setBusy(true);
                try {
                    const started = await request('POST', urls.stage, { size: file.size });
                    let offset = 0;
                    while (offset < file.size) {
                        const end = Math.min(offset + started.chunkBytes, file.size);
                        let attempt = 0;
                        for (;;) {
                            try {
                                const answer = await putChunk(urls.stage + '/' + started.token + '?offset=' + offset, file.slice(offset, end), (loaded) => {
                                    show(t.uploading, offset + loaded, file.size, t.sent.replace(':done', mb(offset + loaded)).replace(':total', mb(file.size)));
                                });
                                offset = answer.received;
                                break;
                            } catch (error) {
                                attempt++;
                                if (attempt >= 3) {
                                    throw error;
                                }
                            }
                        }
                    }
                    show(t.checking_upload, 0, 0, t.checking_upload_note);
                    const staged = await request('POST', urls.stage + '/' + started.token + '/check');
                    await afterStage(staged);
                } catch (error) {
                    fail(error.message);
                }
            });
            document.querySelectorAll('[data-backup-action="auto-restore"]').forEach((button) => button.addEventListener('click', async () => {
                setBusy(true);
                show(t.checking_upload, 0, 0, t.checking_upload_note);
                try {
                    const staged = await request('POST', urls.autoBase + '/' + encodeURIComponent(button.dataset.name) + '/stage');
                    await afterStage(staged);
                } catch (error) {
                    fail(error.message);
                }
            }));

            // Resume on open: follow a job that is running, or report one that finished.
            poll();
        }());
    </script>
@endsection
