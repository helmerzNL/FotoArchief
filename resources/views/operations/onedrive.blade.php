@extends('layouts.app')
@section('title', __('operations.onedrive.title'))
@section('content')
    @php
        $steps = __('operations.onedrive.app_steps');
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

    <section class="card" data-onedrive-step="app">
        <h2>{{ __('operations.onedrive.app_title') }}</h2>
        @unless($hasApp)
            <p>{{ __('operations.onedrive.app_intro') }}</p>
            <ol>
                @foreach($steps as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ol>
        @endunless
        <p>{{ __('operations.onedrive.redirect_label') }}<br><code data-onedrive-redirect>{{ $redirectUri }}</code></p>
        <form method="POST" action="{{ route('admin.operations.onedrive.app') }}">
            @csrf
            <label>{{ __('operations.onedrive.client_id') }} <input type="text" name="client_id" value="{{ old('client_id', $connection->client_id) }}" required autocomplete="off" spellcheck="false"></label>
            <label>{{ __('operations.onedrive.client_secret') }} <input type="password" name="client_secret" autocomplete="new-password" @unless($hasApp) required @endunless></label>
            @if($hasApp)
                <p>{{ __('operations.onedrive.secret_set') }}</p>
            @endif
            <button type="submit">{{ __('operations.onedrive.save_app') }}</button>
        </form>
        @if($hasApp)
            <form method="POST" action="{{ route('admin.operations.onedrive.app.delete') }}">
                @csrf
                @method('DELETE')
                <label><input type="checkbox" name="confirm_delete" value="1" required> {{ __('operations.onedrive.confirm_delete') }}</label>
                <button type="submit">{{ __('operations.onedrive.delete_app') }}</button>
            </form>
        @endif
    </section>

    @if($hasApp)
        <section class="card" data-onedrive-step="connect">
            <h2>{{ __('operations.onedrive.connect_title') }}</h2>
            @if($connected)
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
                    <button type="submit">{{ __('operations.onedrive.connect') }}</button>
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
@endsection
