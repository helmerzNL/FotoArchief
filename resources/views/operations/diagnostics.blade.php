@extends('layouts.app')
@section('title', 'Systeemdiagnose - FotoArchief Operaties')
@section('content')
    <p class="eyebrow">Systeembeheer &middot; Diagnose</p>
    <h1>Systeemdiagnose & Status</h1>
    <p class="intro">Overzicht van runtime-omgeving, extensies, opslag, database, limieten en achtergrondverwerking.</p>

    <div class="notice" style="margin-bottom: 2rem;">
        <strong>Systeemstatus: </strong>
        @if($diagnostics['overall_status'] === 'healthy')
            <span style="color: #059669; font-weight: bold;">Gezond (Alle controles geslaagd)</span>
        @elseif($diagnostics['overall_status'] === 'warning')
            <span style="color: #d97706; font-weight: bold;">Aandachtspunten gedetecteerd</span>
        @else
            <span style="color: #dc2626; font-weight: bold;">Kritieke fouten gedetecteerd</span>
        @endif
        <span style="float: right; color: #6b7280; font-size: 0.875rem;">Gecontroleerd: {{ $diagnostics['timestamp'] }}</span>
    </div>

    <div class="grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">
        {{-- PHP & Omgeving --}}
        <section class="card">
            <h2>PHP & Omgeving</h2>
            <p><strong>PHP Versie:</strong> {{ $diagnostics['php']['version'] }} ({{ $diagnostics['php']['sapi'] }})</p>
            <p><strong>Besturingssysteem:</strong> {{ $diagnostics['php']['os'] }}</p>
            <p><strong>OPcache:</strong> {{ $diagnostics['php']['opcache_enabled'] ? 'Actief' : 'Niet actief' }}</p>
            @if(!empty($diagnostics['php']['remediation']))
                <p style="color: #d97706; font-size: 0.875rem;"><strong>Advies:</strong> {{ $diagnostics['php']['remediation'] }}</p>
            @endif
        </section>

        {{-- PHP Extensies --}}
        <section class="card">
            <h2>PHP Extensies</h2>
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
                        <strong>Schijf [{{ $diskName }}]:</strong> {{ $disk['driver'] }} &middot;
                        @if($disk['accessible'])
                            <span style="color: #059669; font-weight: bold;">Toegankelijk</span>
                        @else
                            <span style="color: #dc2626; font-weight: bold;">Niet toegankelijk</span>
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
            <h2>Database & Migraties</h2>
            <p><strong>Driver:</strong> {{ $diagnostics['database']['driver'] }}</p>
            <p>
                <strong>Verbinding:</strong>
                @if($diagnostics['database']['connected'])
                    <span style="color: #059669;">Actief ({{ $diagnostics['database']['latency_ms'] }} ms)</span>
                @else
                    <span style="color: #dc2626;">Niet verbonden</span>
                @endif
            </p>
            <p><strong>Openstaande migraties:</strong> {{ $diagnostics['database']['pending_migrations'] }}</p>
            @if(!empty($diagnostics['database']['error']))
                <p style="color: #dc2626; font-size: 0.875rem;">{{ $diagnostics['database']['error'] }}</p>
            @endif
            @if(!empty($diagnostics['database']['remediation']))
                <p style="color: #d97706; font-size: 0.875rem;"><strong>Actie:</strong> {{ $diagnostics['database']['remediation'] }}</p>
            @endif
        </section>

        {{-- Limieten & Geheugen --}}
        <section class="card">
            <h2>Limieten & Geheugen</h2>
            <p><strong>upload_max_filesize:</strong> {{ $diagnostics['limits']['php_upload_max_filesize'] }}</p>
            <p><strong>post_max_size:</strong> {{ $diagnostics['limits']['php_post_max_size'] }}</p>
            <p><strong>memory_limit:</strong> {{ $diagnostics['limits']['php_memory_limit'] }}</p>
            <p><strong>max_execution_time:</strong> {{ $diagnostics['limits']['php_max_execution_time'] }}</p>
            <p><strong>Archief uploadlimiet:</strong> {{ $diagnostics['limits']['app_max_upload_mb'] }} MB ({{ number_format($diagnostics['limits']['app_max_image_pixels']) }} pixels)</p>
            @if(!empty($diagnostics['limits']['remediation']))
                <p style="color: #d97706; font-size: 0.875rem;"><strong>Aandacht:</strong> {{ $diagnostics['limits']['remediation'] }}</p>
            @endif
        </section>

        {{-- Malware Scanner --}}
        <section class="card">
            <h2>Malware Scanner</h2>
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
            <h2>Worker & Wachtrij</h2>
            <p><strong>Wachtrij-driver:</strong> {{ $diagnostics['worker']['queue_driver'] }}</p>
            <p><strong>Wachtende/lopende taken:</strong> {{ $diagnostics['worker']['pending_ingest_jobs'] }}</p>
            <p><strong>Mislukte taken:</strong> {{ $diagnostics['worker']['failed_ingest_jobs'] }}</p>
            <p><strong>Vastgelopen claims (&gt;5m):</strong> {{ $diagnostics['worker']['stale_claims'] }}</p>
            @if(!empty($diagnostics['worker']['remediation']))
                <p style="color: #d97706; font-size: 0.875rem;"><strong>Actie:</strong> {{ $diagnostics['worker']['remediation'] }}</p>
            @endif
        </section>
    </div>
@endsection
