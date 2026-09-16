@extends('layouts.app')

@section('title', 'AI-instellingen')

@section('content')
    <h1>AI-instellingen</h1>
    <p>AI staat standaard uit. Schakel alleen capabilities in waarvoor de capability-proof en privacykeuze zijn afgerond.</p>

    @include('operations._nav')

    @if(session('status'))
        <div class="card" role="status">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="card" role="alert">
            <strong>Controleer de AI-instellingen.</strong>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="card">
        <h2>Status</h2>
        <dl>
            <dt>Actief</dt>
            <dd>{{ $settings['active'] ? 'ja' : 'nee' }}</dd>
            <dt>Lokale/eigen provider klaar</dt>
            <dd>{{ $settings['local_ready'] ? 'ja' : 'nee' }}</dd>
            <dt>Externe provider klaar</dt>
            <dd>{{ $settings['external_ready'] ? 'ja' : 'nee' }}</dd>
        </dl>
    </section>

    <form method="POST" action="{{ route('admin.operations.ai.update') }}" class="card">
        @csrf
        <h2>Capabilities en noodstop</h2>
        @foreach([
            'global_enabled' => 'AI globaal inschakelen',
            'emergency_stop' => 'Noodstop actief houden',
            'image_analysis_enabled' => 'Beeldanalyse toestaan',
            'embeddings_enabled' => 'Multimodale embeddings toestaan',
            'local_provider_enabled' => 'Lokale/eigen provider toestaan',
            'external_provider_enabled' => 'Externe provider toestaan',
            'external_processing_allowed' => 'Externe doorgifte expliciet toegestaan',
        ] as $field => $label)
            <label style="display:block;margin:0.5rem 0;">
                <input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $settings[$field]) === true || old($field, $settings[$field]) === '1')>
                {{ $label }}
            </label>
        @endforeach

        <h2>Endpoints en privacy</h2>
        <label>Lokale/eigen endpoint
            <input type="url" name="local_endpoint" value="{{ old('local_endpoint', $settings['local_endpoint']) }}" placeholder="http://ai-service:8080 of https://ai.example.org">
        </label>
        <label>Externe endpoint
            <input type="url" name="external_endpoint" value="{{ old('external_endpoint', $settings['external_endpoint']) }}" placeholder="https://provider.example.org">
        </label>
        <label>Providerregio
            <input type="text" name="provider_region" value="{{ old('provider_region', $settings['provider_region']) }}" placeholder="EU/NL of contractuele regio">
        </label>
        <label>Retentie/training-notitie
            <textarea name="retention_notice" rows="3" placeholder="Beschrijf retentie, training opt-out en gegevensscope">{{ old('retention_notice', $settings['retention_notice']) }}</textarea>
        </label>

        <h2>Numerieke limieten</h2>
        <label>Max assets per AI-batch
            <input type="number" min="1" max="25" name="max_assets_per_batch" value="{{ old('max_assets_per_batch', $settings['max_assets_per_batch']) }}">
        </label>
        <label>Max afbeeldingsrand voor AI-afgeleide
            <input type="number" min="256" max="1024" name="derivative_max_pixels" value="{{ old('derivative_max_pixels', $settings['derivative_max_pixels']) }}">
        </label>
        <label>Provider timeout seconden
            <input type="number" min="5" max="60" name="request_timeout_seconds" value="{{ old('request_timeout_seconds', $settings['request_timeout_seconds']) }}">
        </label>
        <label>Maandelijks extern budget in centen
            <input type="number" min="0" name="monthly_external_budget_cents" value="{{ old('monthly_external_budget_cents', $settings['monthly_external_budget_cents']) }}">
        </label>

        <p>Secrets worden hier niet opgeslagen. Zet provider API-sleutels alleen in de private runtimeomgeving.</p>
        <button type="submit">AI-instellingen opslaan</button>
    </form>

    <form method="POST" action="{{ route('admin.operations.ai.index') }}" class="card">
        @csrf
        <h2>Embeddingindex bouwen</h2>
        <p>Indexeer alleen geselecteerde, gescande primaire bestanden. De worker vraagt beeldembeddings op; tekstqueries moeten later hetzelfde model_space gebruiken.</p>
        <label>Asset-id's
            <textarea name="asset_ids" rows="3" placeholder="ULID's gescheiden door komma's of regels"></textarea>
        </label>
        <label>Provider
            <select name="provider">
                <option value="local">Lokale/eigen provider</option>
                <option value="external">Externe provider</option>
            </select>
        </label>
        <button type="submit">Embeddingindex starten</button>
    </form>
@endsection
