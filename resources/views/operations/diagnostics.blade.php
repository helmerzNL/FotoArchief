@extends('layouts.app')
@section('title', 'Systeemdiagnose - FotoArchief Operaties')
@section('content')
    @include('operations._nav')
    <p class="eyebrow">{{ __('operations.generated.t_1e52ed7b533ed793') }}</p>
    <h1>{{ __('operations.generated.t_9f02386c02b97a2d') }}</h1>
    <p class="intro">{{ __('operations.generated.t_191709f61a9b65f3') }}</p>

    <div class="notice" style="margin-bottom: 2rem;">
        <strong>Systeemstatus: </strong>
        @if($diagnostics['overall_status'] === 'healthy')
            <span style="color: #059669; font-weight: bold;">{{ __('operations.generated.t_4762aacfc9535c0e') }}</span>
        @elseif($diagnostics['overall_status'] === 'warning')
            <span style="color: #d97706; font-weight: bold;">{{ __('operations.generated.t_1581a55024c0fab4') }}</span>
        @else
            <span style="color: #dc2626; font-weight: bold;">{{ __('operations.generated.t_e103e5b8688e9e1e') }}</span>
        @endif
        <span style="float: right; color: #6b7280; font-size: 0.875rem;">Gecontroleerd: {{ $diagnostics['timestamp'] }}</span>
    </div>

    <div class="grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">
        {{-- PHP & Omgeving --}}
        <section class="card">
            <h2>{!! __('operations.generated.t_76ab2f518a011915') !!}</h2>
            <p><strong>{{ __('operations.generated.t_c43cb6dda713f419') }}</strong> {{ $diagnostics['php']['version'] }} ({{ $diagnostics['php']['sapi'] }})</p>
            <p><strong>Besturingssysteem:</strong> {{ $diagnostics['php']['os'] }}</p>
            <p><strong>OPcache:</strong> {{ $diagnostics['php']['opcache_enabled'] ? 'Actief' : 'Niet actief' }}</p>
            @if(!empty($diagnostics['php']['remediation']))
                <p style="color: #d97706; font-size: 0.875rem;"><strong>Advies:</strong> {{ $diagnostics['php']['remediation'] }}</p>
            @endif
        </section>

        {{-- PHP Extensies --}}
        <section class="card">
            <h2>{{ __('operations.generated.t_b014400280d633b0') }}</h2>
            <ul style="list-style: none; padding: 0; margin: 0;">
                @foreach($diagnostics['extensions']['extensions'] as $ext => $info)
                    <li style="margin-bottom: 0.25rem;">
                        @if($info['loaded'])
                            <span style="color: #059669;">&#10003;</span>
                        @else
                            <span style="color: #dc2626;">&#10007;</span>
                        @endif
                        <strong>{{ $ext }}</strong> &ndash; <span style="font-size: 0.875rem; color: #6b7280;">{{ $info['purpose'] }}</span>
                    </li>
                @endforeach
            </ul>
            @if(!empty($diagnostics['extensions']['remediation']))
                <p style="color: #dc2626; font-size: 0.875rem; margin-top: 0.5rem;"><strong>Actie:</strong> {{ $diagnostics['extensions']['remediation'] }}</p>
            @endif
        </section>

        {{-- Opslag & Schijfruimte --}}
        <section class="card">
            <h2>Opslag</h2>
            @foreach($diagnostics['storage']['disks'] as $diskName => $disk)
                <div style="margin-bottom: 0.75rem;">
                    <p>
                        <strong>{{ __('operations.generated.t_f0b24ec433d81fc5') }}{{ $diskName }}]:</strong> {{ $disk['driver'] }} &middot;
                        @if($disk['accessible'])
                            <span style="color: #059669; font-weight: bold;">Toegankelijk</span>
                        @else
                            <span style="color: #dc2626; font-weight: bold;">{{ __('operations.generated.t_cdab42db694c30f3') }}</span>
                        @endif
                    </p>
                    @if(!empty($disk['error']))
                        <p style="color: #dc2626; font-size: 0.875rem;">{{ $disk['error'] }}</p>
                    @endif
                </div>
            @endforeach
            @if(!empty($diagnostics['storage']['remediation']))
                <p style="color: #dc2626; font-size: 0.875rem;"><strong>Actie:</strong> {{ $diagnostics['storage']['remediation'] }}</p>
            @endif
        </section>

        {{-- Database & Migraties --}}
        <section class="card">
            <h2>{!! __('operations.generated.t_caf1bbe359153328') !!}</h2>
            <p><strong>Driver:</strong> {{ $diagnostics['database']['driver'] }}</p>
            <p>
                <strong>Verbinding:</strong>
                @if($diagnostics['database']['connected'])
                    <span style="color: #059669;">{{ __('operations.generated.t_bc6ba2751efc9f31') }}{{ $diagnostics['database']['latency_ms'] }} ms)</span>
                @else
                    <span style="color: #dc2626;">{{ __('operations.generated.t_3f714d9396218d8a') }}</span>
                @endif
            </p>
            <p><strong>{{ __('operations.generated.t_74fe179eaa45cea4') }}</strong> {{ $diagnostics['database']['pending_migrations'] }}</p>
            @if(!empty($diagnostics['database']['error']))
                <p style="color: #dc2626; font-size: 0.875rem;">{{ $diagnostics['database']['error'] }}</p>
            @endif
            @if(!empty($diagnostics['database']['remediation']))
                <p style="color: #d97706; font-size: 0.875rem;"><strong>Actie:</strong> {{ $diagnostics['database']['remediation'] }}</p>
            @endif
        </section>

        {{-- Limieten & Geheugen --}}
        <section class="card">
            <h2>{!! __('operations.generated.t_0f3e639d9f676e5d') !!}</h2>
            <p><strong>upload_max_filesize:</strong> {{ $diagnostics['limits']['php_upload_max_filesize'] }}</p>
            <p><strong>post_max_size:</strong> {{ $diagnostics['limits']['php_post_max_size'] }}</p>
            <p><strong>memory_limit:</strong> {{ $diagnostics['limits']['php_memory_limit'] }}</p>
            <p><strong>max_execution_time:</strong> {{ $diagnostics['limits']['php_max_execution_time'] }}</p>
            <p><strong>{{ __('operations.generated.t_11bf3a2ff4f788a7') }}</strong> {{ $diagnostics['limits']['app_max_upload_mb'] }} {{ __('operations.generated.t_ce3ebe69c651a569') }}{{ number_format($diagnostics['limits']['app_max_image_pixels']) }} pixels)</p>
            @if(!empty($diagnostics['limits']['remediation']))
                <p style="color: #d97706; font-size: 0.875rem;"><strong>Aandacht:</strong> {{ $diagnostics['limits']['remediation'] }}</p>
            @endif
        </section>

        {{-- Malware Scanner --}}
        <section class="card">
            <h2>{{ __('operations.generated.t_d2a7db130266278c') }}</h2>
            <p><strong>Type:</strong> {{ $diagnostics['scanner']['scanner'] }}</p>
            <p><strong>Status:</strong> {{ $diagnostics['scanner']['message'] }}</p>
            @if(isset($diagnostics['scanner']['endpoint']))
                <p><strong>Endpoint:</strong> {{ $diagnostics['scanner']['endpoint'] }}</p>
            @endif
            @if(!empty($diagnostics['scanner']['error']))
                <p style="color: #dc2626; font-size: 0.875rem;">{{ $diagnostics['scanner']['error'] }}</p>
            @endif
            @if(!empty($diagnostics['scanner']['remediation']))
                <p style="color: #d97706; font-size: 0.875rem;"><strong>Advies:</strong> {{ $diagnostics['scanner']['remediation'] }}</p>
            @endif
        </section>

        {{-- Worker & Wachtrij --}}
        <section class="card">
            <h2>{!! __('operations.generated.t_57add9377236f360') !!}</h2>
            <p><strong>Wachtrij-driver:</strong> {{ $diagnostics['worker']['queue_driver'] }}</p>
            <p><strong>{{ __('operations.generated.t_42f6aff9740111b0') }}</strong> {{ $diagnostics['worker']['pending_ingest_jobs'] }}</p>
            <p><strong>{{ __('operations.generated.t_480c7c329bb0cf01') }}</strong> {{ $diagnostics['worker']['failed_ingest_jobs'] }}</p>
            <p><strong>{{ __('operations.generated.t_17941d01964a6bc5') }}</strong> {{ $diagnostics['worker']['stale_claims'] }}</p>
            @if(!empty($diagnostics['worker']['remediation']))
                <p style="color: #d97706; font-size: 0.875rem;"><strong>Actie:</strong> {{ $diagnostics['worker']['remediation'] }}</p>
            @endif
        </section>

        {{-- Scheduler & Worker Activiteit --}}
        <section class="card">
            <h2>{!! __('operations.generated.t_bbc34b1fc4c60255') !!}</h2>
            @foreach($diagnostics['activity']['roles'] as $role => $heartbeat)
                <div style="margin-bottom: 0.75rem;">
                    <p>
                        <strong>{{ $role === 'worker' ? 'Ingest-worker' : 'Scheduler' }}:</strong>
                        @if($heartbeat['seen'] && !$heartbeat['stale'])
                            <span style="color: #059669; font-weight: bold;">{{ __('operations.generated.t_aefaf786be51d115') }}</span>
                        @elseif($heartbeat['seen'])
                            <span style="color: #d97706; font-weight: bold;">verouderd</span>
                        @else
                            <span style="color: #d97706; font-weight: bold;">{{ __('operations.generated.t_b3b0b14b3a29166c') }}</span>
                        @endif
                    </p>
                    <p style="font-size: 0.875rem; color: #6b7280;">
                        Status: {{ $heartbeat['state'] }}@if($heartbeat['last_seen_at']) {{ __('operations.generated.t_2be4a171b96d313e') }} {{ $heartbeat['last_seen_at'] }}@endif
                    </p>
                </div>
            @endforeach
            @if(!empty($diagnostics['activity']['remediation']))
                <p style="color: #d97706; font-size: 0.875rem;"><strong>Actie:</strong> {{ $diagnostics['activity']['remediation'] }}</p>
            @endif
        </section>
    </div>
@endsection
