@extends('layouts.app')
@section('title', __('recovery.title'))
@section('content')
    @include('operations._nav')
    <h1>{{ __('recovery.title') }}</h1>
    <section class="card">
        <h2>{{ __('recovery.installation') }}</h2>
        <p>{{ __('recovery.probe_notice') }}</p>
        <form method="post" action="{{ route('admin.operations.recovery.check') }}">
            @csrf
            <input type="hidden" name="kind" value="installation">
            <label><input type="checkbox" name="confirm" value="1" required> {{ __('recovery.confirm_check') }}</label>
            <button>{{ __('recovery.run_installation') }}</button>
        </form>
    </section>
    <section class="card">
        <h2>{{ __('recovery.upgrade') }}</h2>
        <p>{{ __('recovery.preflight_notice') }}</p>
        <form method="post" action="{{ route('admin.operations.recovery.check') }}">
            @csrf
            <input type="hidden" name="kind" value="upgrade">
            <label>{{ __('recovery.target_version') }} <input name="target_version" placeholder="0.10.0" required></label>
            <label>{{ __('recovery.required_bytes') }} <input name="required_free_bytes" type="number" min="1" required></label>
            <label><input type="checkbox" name="confirm" value="1" required> {{ __('recovery.confirm_check') }}</label>
            <button>{{ __('recovery.run_upgrade') }}</button>
        </form>
    </section>
    <section class="card"><h2>{{ __('recovery.reports') }}</h2>
        @foreach($checks as $check)
            <details><summary>{{ $check['kind'] }} · {{ $check['created_at'] }} · {{ $check['report']['ready'] ? __('recovery.ready') : __('recovery.blocked') }}</summary>
                <pre style="white-space: pre-wrap;">{{ json_encode($check['report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </details>
        @endforeach
    </section>
    <section class="card"><h2>{{ __('recovery.backups') }}</h2>
        <p>{{ __('recovery.register_notice') }}</p>
        <code>php artisan operations:register-backup /private/backup</code>
        @foreach($backups as $backup)
            <article><h3>{{ $backup->id }} · {{ $backup->version }}</h3>
                <p>{{ __('recovery.backup_summary', ['location' => $backup->location, 'size' => $backup->byte_size, 'hash' => $backup->manifest_sha256]) }}</p>
                <p>{{ __('recovery.checksum_at') }}: {{ $backup->checksum_verified_at }} · {{ __('recovery.last_restore') }}: {{ $backup->drills->first()?->finished_at ?? __('recovery.unproven') }}</p>
            </article>
        @endforeach
        {{ $backups->links() }}
    </section>
    <section class="card"><h2>{{ __('recovery.drill') }}</h2>
        <ol><li>{{ __('recovery.drill_prepare') }}</li><li>{{ __('recovery.drill_run') }}</li><li>{{ __('recovery.drill_verify') }}</li></ol>
        <code style="overflow-wrap:anywhere;">php artisan operations:restore-drill BACKUP_ID --database=archive_restore_drill --directory=/private/new-drill --confirm-empty-target</code>
        @foreach($drills as $drill)
            <details><summary>{{ $drill->id }} · {{ $drill->status }} · {{ $drill->finished_at ?? $drill->created_at }}</summary>
                <p>{{ $drill->target_database }} · {{ $drill->target_directory }}</p>
                @if($drill->error)<p role="alert">{{ $drill->error }}</p>@endif
                <pre style="white-space:pre-wrap;">{{ json_encode($drill->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </details>
        @endforeach
        {{ $drills->links() }}
    </section>
    <section class="card"><h2>{{ __('recovery.incidents') }}</h2>
        <p>{{ __('recovery.incident_notice') }}</p>
        @foreach($incidents as $incident)
            <article><h3>{{ $incident->title }} · {{ $incident->status }} · {{ $incident->severity }}</h3>
                <p><a href="{{ route('admin.operations.recovery.timeline', $incident->id) }}">{{ __('evidence.timeline') }}</a></p>
                <p>{{ $incident->detail }} · {{ $incident->last_seen_at }} · {{ $incident->observations }}</p>
                @if($incident->delivery_error)<p role="alert">{{ $incident->delivery_error }}</p>@endif
                @if($incident->acknowledged_at)<p>{{ __('recovery.acknowledged') }} {{ $incident->acknowledged_at }}</p>
                @elseif($incident->status === 'open')
                    <form method="post" action="{{ route('admin.operations.recovery.acknowledge', $incident->id) }}">
                        @csrf
                        <label><input type="checkbox" name="confirm" value="1" required> {{ __('recovery.confirm_acknowledge') }}</label>
                        <button>{{ __('recovery.acknowledge') }}</button>
                    </form>
                @endif
            </article>
        @endforeach
    </section>
@endsection
