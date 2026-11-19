@extends('layouts.app')
@section('title', __('operations.onedrive.title'))
@section('content')
    @php
        $advancedSteps = __('operations.onedrive.advanced.steps');
        $privacy = __('operations.onedrive.privacy');
        $actions = __('operations.onedrive.actions');
        $hints = __('operations.onedrive.hints');
        $modes = __('operations.onedrive.modes');
        $statLabels = __('operations.onedrive.stats');
        $columns = __('operations.onedrive.columns');
        $hasApp = $connection->hasApp();
        $connected = $connection->isConnected();
        $hasFolder = $connection->hasFolder();
        $connectedAt = $connection->connected_at?->timezone(config('app.timezone'))->format('d-m-Y H:i') ?? '—';
        $summaries = [];
        foreach ($runs as $run) {
            $stats = is_array($run->result['stats'] ?? null) ? $run->result['stats'] : (is_array($run->payload['stats'] ?? null) ? $run->payload['stats'] : []);
            $parts = [];
            foreach ($statLabels as $key => $label) {
                if ((int) ($stats[$key] ?? 0) > 0) {
                    $parts[] = (int) $stats[$key].' '.$label;
                }
            }
            $complete = ($run->result['complete'] ?? true) !== false;
            $summaries[$run->id] = [
                'mode' => $modes[$run->payload['mode'] ?? 'import'] ?? '',
                'text' => implode(' · ', $parts),
                'partial' => ! $complete,
            ];
        }
    @endphp
    <h1>{{ __('operations.onedrive.title') }}</h1>
    <p class="intro">{{ __('operations.onedrive.intro') }}</p>

    @unless($hasApp)
        <p role="alert" data-onedrive-unavailable>{{ __('operations.onedrive.errors.not_available') }}</p>
    @endunless

    @if($hasApp)
        <section class="card" data-onedrive-step="connect">
            <h2>{{ __('operations.onedrive.connect_title') }}</h2>
            @if($device !== null)
                <div data-onedrive-device data-poll-url="{{ route('admin.operations.onedrive.poll', [], false) }}" data-interval="{{ $device['interval'] }}">
                    <p>{{ __('operations.onedrive.device.intro') }}</p>
                    <ol>
                        <li><a href="{{ $device['verification_uri'] }}" target="_blank" rel="noopener noreferrer" data-onedrive-verification>{{ __('operations.onedrive.device.open', ['site' => preg_replace('#^https://(www\.)?#', '', $device['verification_uri'])]) }}</a></li>
                        <li>{{ __('operations.onedrive.device.enter') }}
                            <strong data-onedrive-user-code style="font-size: 1.75rem; letter-spacing: 0.15em; font-family: ui-monospace, monospace;">{{ $device['user_code'] }}</strong>
                            <button type="button" data-onedrive-copy hidden>{{ __('operations.onedrive.device.copy') }}</button>
                        </li>
                        <li>{{ __('operations.onedrive.device.approve') }}</li>
                    </ol>
                    <p role="status" aria-live="polite" data-onedrive-device-status>{{ __('operations.onedrive.device.waiting') }}</p>
                    <form method="POST" action="{{ route('admin.operations.onedrive.poll') }}" data-onedrive-poll-form>
                        @csrf
                        <button type="submit">{{ __('operations.onedrive.device.done') }}</button>
                    </form>
                    <form method="POST" action="{{ route('admin.operations.onedrive.cancel') }}">
                        @csrf
                        <button type="submit">{{ __('operations.onedrive.device.cancel') }}</button>
                    </form>
                </div>
                <script>
                    (() => {
                        const box = document.querySelector('[data-onedrive-device]');
                        const status = box.querySelector('[data-onedrive-device-status]');
                        const copy = box.querySelector('[data-onedrive-copy]');
                        const code = box.querySelector('[data-onedrive-user-code]').textContent.trim();
                        const messages = @json(__('operations.onedrive.device'));
                        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                        if (navigator.clipboard) {
                            copy.hidden = false;
                            copy.addEventListener('click', async () => {
                                try {
                                    await navigator.clipboard.writeText(code);
                                    copy.textContent = messages.copied;
                                } catch (error) {
                                    copy.hidden = true;
                                }
                            });
                        }
                        let failures = 0;
                        const poll = async () => {
                            let answer;
                            try {
                                const response = await fetch(box.dataset.pollUrl, {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                                });
                                answer = await response.json();
                            } catch (error) {
                                failures += 1;
                                if (failures >= 3) {
                                    status.textContent = messages.unreachable;
                                    return;
                                }
                                window.setTimeout(poll, 5000);
                                return;
                            }
                            failures = 0;
                            if (answer.status === 'pending') {
                                window.setTimeout(poll, Math.max(1, answer.retry_after ?? Number(box.dataset.interval)) * 1000);
                                return;
                            }
                            if (answer.status === 'connected' && answer.redirect) {
                                status.textContent = messages.connected;
                                window.location.assign(answer.redirect);
                                return;
                            }
                            status.setAttribute('role', 'alert');
                            status.textContent = answer.message ?? messages.unreachable;
                            box.querySelector('[data-onedrive-poll-form]').hidden = true;
                        };
                        window.setTimeout(poll, Number(box.dataset.interval) * 1000);
                    })();
                </script>
            @elseif($connected)
                <p data-onedrive-account>{{ __('operations.onedrive.connected_as', ['name' => $connection->account_name ?? __('operations.onedrive.unknown_owner'), 'date' => $connectedAt]) }}</p>
                <form method="POST" action="{{ route('admin.operations.onedrive.connect') }}">
                    @csrf
                    <button type="submit">{{ __('operations.onedrive.reconnect') }}</button>
                </form>
                <form method="POST" action="{{ route('admin.operations.onedrive.disconnect') }}">
                    @csrf
                    <label><input type="checkbox" name="confirm_disconnect" value="1" required> {{ __('operations.onedrive.confirm_disconnect') }}</label>
                    <button type="submit">{{ __('operations.onedrive.disconnect') }}</button>
                </form>
                <p>{{ __('operations.onedrive.revoke_hint') }}</p>
            @else
                <p>{{ __('operations.onedrive.connect_intro') }}</p>
                <form method="POST" action="{{ route('admin.operations.onedrive.connect') }}">
                    @csrf
                    <button type="submit" data-onedrive-connect>{{ __('operations.onedrive.connect') }}</button>
                </form>
            @endif
        </section>
    @endif

    @if($connected)
        <section class="card" data-onedrive-step="folder">
            <h2>{{ __('operations.onedrive.folder_title') }}</h2>
            <p data-onedrive-folder>{{ $hasFolder ? __('operations.onedrive.current_folder', ['folder' => $connection->folder_path]) : __('operations.onedrive.no_folder') }}</p>
            @if($browseError !== null)
                <p role="alert">{{ $browseError }}</p>
            @endif
            @if($browse !== null)
                <h3>{{ $browse['folder']['parent'] === null ? __('operations.onedrive.root') : $browse['folder']['path'] }}</h3>
                @if($browse['folder']['parent'] !== null)
                    <p><a href="{{ route('admin.operations.onedrive', ['browse' => $browse['folder']['parent']]) }}">{{ __('operations.onedrive.browse_up') }}</a></p>
                @endif
                <form method="POST" action="{{ route('admin.operations.onedrive.folder') }}">
                    @csrf
                    <input type="hidden" name="item_id" value="{{ $browse['folder']['parent'] === null ? 'root' : $browse['folder']['id'] }}">
                    <button type="submit">{{ __('operations.onedrive.choose_this') }}</button>
                </form>
                @if($browse['children'] === [])
                    <p>{{ __('operations.onedrive.no_subfolders') }}</p>
                @else
                    <ul data-onedrive-children>
                        @foreach($browse['children'] as $child)
                            <li><a href="{{ route('admin.operations.onedrive', ['browse' => $child['id']]) }}">{{ $child['name'] }}</a> <small>{{ __('operations.onedrive.subfolders', ['count' => $child['folders']]) }}</small></li>
                        @endforeach
                    </ul>
                @endif
            @else
                <p><a href="{{ route('admin.operations.onedrive', ['browse' => 'root']) }}">{{ $hasFolder ? __('operations.onedrive.browse_other') : __('operations.onedrive.browse') }}</a></p>
            @endif
        </section>
    @endif

    @if($connected && $hasFolder)
        <section class="card" data-onedrive-step="import">
            <h2>{{ __('operations.onedrive.import_title') }}</h2>
            <p>{{ __('operations.onedrive.import_intro') }}</p>
            @if($activeRun !== null)
                <p role="status">{{ __('operations.onedrive.active') }} <a href="{{ route('admin.operations.runs.show', $activeRun) }}">{{ __('operations.onedrive.view_run') }}</a></p>
            @else
                <p>{{ $hints[$importLabel] }}</p>
                <form method="POST" action="{{ route('admin.operations.onedrive.start') }}">
                    @csrf
                    <input type="hidden" name="mode" value="{{ \App\Modules\Ingest\Services\OneDriveImportService::MODE_SURVEY }}">
                    <button type="submit">{{ __('operations.onedrive.survey') }}</button>
                </form>
                <form method="POST" action="{{ route('admin.operations.onedrive.start') }}">
                    @csrf
                    <input type="hidden" name="mode" value="{{ \App\Modules\Ingest\Services\OneDriveImportService::MODE_IMPORT }}">
                    <button type="submit" data-onedrive-import="{{ $importLabel }}">{{ $actions[$importLabel] }}</button>
                </form>
            @endif
        </section>
    @endif

    @if($runs->isNotEmpty())
        <section class="card">
            <h2>{{ __('operations.onedrive.runs_title') }}</h2>
            <table>
                <thead><tr><th>{{ $columns['started'] }}</th><th>{{ $columns['kind'] }}</th><th>{{ $columns['status'] }}</th><th>{{ $columns['result'] }}</th></tr></thead>
                <tbody>
                    @foreach($runs as $run)
                        <tr>
                            <td><a href="{{ route('admin.operations.runs.show', $run) }}">{{ $run->created_at?->timezone(config('app.timezone'))->format('d-m-Y H:i') }}</a></td>
                            <td>{{ $summaries[$run->id]['mode'] }}</td>
                            <td>{{ $run->status }}@if($summaries[$run->id]['partial']) ({{ __('operations.onedrive.partial') }})@endif</td>
                            <td>{{ $summaries[$run->id]['text'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    <section class="card">
        <h2>{{ __('operations.onedrive.privacy_title') }}</h2>
        <ul>
            @foreach($privacy as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    </section>

    <details class="card" data-onedrive-advanced @unless($hasApp) open @endunless>
        <summary>{{ __('operations.onedrive.advanced.title') }}</summary>
        <p data-onedrive-app>{{ $connection->usesCustomApp() ? __('operations.onedrive.advanced.current_custom', ['id' => $connection->client_id]) : ($defaultClientId !== '' ? __('operations.onedrive.advanced.current_default') : __('operations.onedrive.advanced.current_none')) }}</p>
        <p>{{ __('operations.onedrive.advanced.intro') }}</p>
        <ol>
            @foreach($advancedSteps as $step)
                <li>{{ $step }}</li>
            @endforeach
        </ol>
        <form method="POST" action="{{ route('admin.operations.onedrive.app') }}">
            @csrf
            <label>{{ __('operations.onedrive.client_id') }} <input type="text" name="client_id" value="{{ old('client_id', $connection->client_id) }}" autocomplete="off" spellcheck="false" placeholder="00000000-0000-0000-0000-000000000000"></label>
            <p>{{ __('operations.onedrive.advanced.hint') }}</p>
            <button type="submit">{{ __('operations.onedrive.save_app') }}</button>
        </form>
    </details>
@endsection
