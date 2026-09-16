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
            @foreach(\App\Modules\Ai\Services\AiConfigurationService::NATIVE_PROVIDERS as $native)
                <dt>{{ ucfirst($native) }} geconfigureerd / klaar</dt>
                <dd>{{ $settings["{$native}_configured"] ? 'ja' : 'nee' }} / {{ $settings["{$native}_ready"] ? 'ja' : 'nee' }}</dd>
            @endforeach
            <dt>Beeldanalyse gereed (provider + model + toestemming)</dt>
            <dd>{{ $settings['image_analysis_ready'] ? 'ja' : 'nee' }}</dd>
            <dt>Embeddings gereed (provider + model + toestemming)</dt>
            <dd>{{ $settings['embeddings_ready'] ? 'ja' : 'nee' }}</dd>
        </dl>
        <p>"Geconfigureerd" betekent dat de database een versleutelde API-sleutel en een positief budget bevat. De sleutel wordt nooit getoond, teruggegeven, gelogd of geserialiseerd.</p>
    </section>

    <section class="card">
        <h2>Providers / Providers</h2>
        <p>Stel modellen, kosten en maandbudgetten per provider in. Native endpoints zijn vast; alleen de custom externe provider gebruikt een door de beheerder ingesteld publiek HTTPS-endpoint.</p>
        @foreach($providerStatuses as $provider)
            <article class="card">
                <h3>{{ ucfirst($provider['provider']) }}</h3>
                <dl>
                    <dt>Status sleutel</dt><dd>{{ $provider['has_api_key'] ? 'ingesteld' : 'niet ingesteld' }}</dd>
                    <dt>{{ $provider['provider'] === 'external' ? 'Endpoint' : 'Vaste base-URL' }}</dt><dd><code>{{ $provider['base_url'] ?: 'niet ingesteld' }}</code></dd>
                    @if($provider['api_version'])
                        <dt>Vaste API-versie</dt><dd>{{ $provider['api_version'] }}</dd>
                    @endif
                    @if($provider['embedding_model_allowlist'])
                        <dt>Vaste OpenRouter-allowlist</dt><dd>{{ implode(', ', $provider['embedding_model_allowlist']) }}</dd>
                    @endif
                </dl>
                <form method="POST" action="{{ route('admin.operations.ai.provider.update', $provider['provider']) }}">
                    @csrf
                    <label><input type="checkbox" name="enabled" value="1" @checked($provider['enabled'])> Provider toestaan</label>
                    <label>Visionmodel <input type="text" name="vision_model" value="{{ $provider['vision_model'] }}"></label>
                    <label>Embeddingmodel <input type="text" name="embedding_model" value="{{ $provider['embedding_model'] }}"></label>
                    @if($provider['provider'] === 'external')
                        <label>Publiek HTTPS-endpoint <input type="url" name="endpoint" value="{{ $provider['endpoint'] }}" required></label>
                        <label>Providerregio <input type="text" name="provider_region" value="{{ $provider['provider_region'] }}" required></label>
                        <label>Retentie/training-notitie <textarea name="retention_notice" rows="3" required>{{ $provider['retention_notice'] }}</textarea></label>
                    @endif
                    <label>Kosten beeldanalyse (centen) <input type="number" min="0" name="cost_cents_per_image" value="{{ $provider['cost_cents_per_image'] }}"></label>
                    <label>Kosten embedding (centen) <input type="number" min="0" name="cost_cents_per_embedding" value="{{ $provider['cost_cents_per_embedding'] }}"></label>
                    <label>Maandbudget (centen) <input type="number" min="0" name="monthly_budget_cents" value="{{ $provider['monthly_budget_cents'] }}"></label>
                    <button type="submit">Providerinstellingen opslaan</button>
                </form>
                <form method="POST" action="{{ route('admin.operations.ai.provider.key.set', $provider['provider']) }}">
                    @csrf
                    <label>API-sleutel instellen/vervangen <input type="password" name="api_key" autocomplete="new-password" required></label>
                    <button type="submit">Sleutel opslaan</button>
                </form>
                @if($provider['has_api_key'])
                    <form method="POST" action="{{ route('admin.operations.ai.provider.key.delete', $provider['provider']) }}">
                        @csrf
                        @method('DELETE')
                        <label><input type="checkbox" name="confirm_delete" value="1" required> Ik bevestig dat deze API-sleutel definitief moet worden verwijderd.</label>
                        <button type="submit">API-sleutel expliciet verwijderen</button>
                    </form>
                @endif
            </article>
        @endforeach
    </section>

    @if(session('connection_test'))
        @php($test = session('connection_test'))
        <section class="card" role="status">
            <h2>Verbindingstest resultaat: {{ ucfirst($test['provider']) }} / {{ $test['capability'] === 'embeddings' ? 'embeddings' : 'beeldanalyse' }}</h2>
            <p>{{ $test['message'] }}</p>
            @if($test['status'] === 'ok' && array_key_exists('models_count', $test))
                <p>Zichtbare modellen voor deze sleutel: {{ $test['models_count'] }}. Geconfigureerd model "{{ $test['model_configured'] }}" gevonden: {{ $test['model_found'] ? 'ja' : 'nee' }}.</p>
            @endif
        </section>
    @endif

    <section class="card">
        <h2>Verbindingstest (kosteloos)</h2>
        <p>Test alleen of de sleutel geldig is en welke modellen zichtbaar zijn. Dit voert nooit een betaalde beeldanalyse of embedding uit. Een echte beeldanalyse-proof gebeurt uitsluitend via de "Beeldanalyse starten" actie hieronder, met een echte budgetreservering.</p>
        <form method="POST" action="{{ route('admin.operations.ai.test-connection') }}">
            @csrf
            <label>Provider
                <select name="test_provider">
                    @foreach(\App\Modules\Ai\Services\AiConfigurationService::IMAGE_ANALYSIS_PROVIDERS as $providerOption)
                        <option value="{{ $providerOption }}">{{ ucfirst($providerOption) }}</option>
                    @endforeach
                </select>
            </label>
            <label>Capability
                <select name="test_capability">
                    <option value="image_analysis">Beeldanalyse</option>
                    <option value="embeddings">Embeddings</option>
                </select>
            </label>
            <button type="submit">Verbinding testen</button>
        </form>
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
            'external_processing_allowed' => 'Externe doorgifte expliciet toegestaan',
        ] as $field => $label)
            <label style="display:block;margin:0.5rem 0;">
                <input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $settings[$field]) === true || old($field, $settings[$field]) === '1')>
                {{ $label }}
            </label>
        @endforeach
        <p>Native providers sturen de geselecteerde afgeleide (beeldanalyse) of zoektekst (embeddings) rechtstreeks naar de eigen API van OpenAI, Anthropic, Google Gemini of OpenRouter. Er vindt nooit automatische failover tussen providers plaats: elke capability gebruikt precies de hieronder gekozen provider.</p>

        <h2>Beeldanalyse: provider, model en toestemming</h2>
        <label>Provider voor beeldanalyse
            <select name="image_analysis_provider">
                <option value="">(geen)</option>
                @foreach(\App\Modules\Ai\Services\AiConfigurationService::IMAGE_ANALYSIS_PROVIDERS as $provider)
                    <option value="{{ $provider }}" @selected(old('image_analysis_provider', $settings['image_analysis_provider']) === $provider)>{{ ucfirst($provider) }}</option>
                @endforeach
            </select>
        </label>
        <p>Voor databaseproviders wordt het beeldanalysemodel uit de providersectie gebruikt. Alleen de lokale/eigen provider gebruikt hier nog zijn capabilitymodel.</p>
        <label>Lokaal model voor beeldanalyse <input type="text" name="image_analysis_model" value="{{ old('image_analysis_model', $settings['image_analysis_model']) }}"></label>
        <label style="display:block;margin:0.5rem 0;">
            <input type="checkbox" name="image_analysis_native_consent" value="1" @checked(old('image_analysis_native_consent', $settings['image_analysis_native_consent']) === true || old('image_analysis_native_consent', $settings['image_analysis_native_consent']) === '1')>
            Ik geef expliciet toestemming dat de geselecteerde afgeleide (max 1024px, metadata verwijderd) naar de gekozen native provider wordt verstuurd voor beeldanalyse.
        </label>

        <h2>Embeddings: provider, model en toestemming</h2>
        <p>Alleen aantoonbaar multimodale, gedeelde tekst/beeld-vectorruimtes worden aangeboden: OpenAI-tekstembeddings en Anthropic bieden dat niet native, dus die staan hier niet in de lijst.</p>
        <label>Provider voor embeddings
            <select name="embeddings_provider">
                <option value="">(geen)</option>
                @foreach(\App\Modules\Ai\Services\AiConfigurationService::EMBEDDINGS_PROVIDERS as $provider)
                    <option value="{{ $provider }}" @selected(old('embeddings_provider', $settings['embeddings_provider']) === $provider)>{{ ucfirst($provider) }}</option>
                @endforeach
            </select>
        </label>
        <p>Voor databaseproviders wordt het embeddingmodel uit de providersectie gebruikt.</p>
        <label>Lokaal embeddingmodel <input type="text" name="embeddings_model" value="{{ old('embeddings_model', $settings['embeddings_model']) }}"></label>
        <label style="display:block;margin:0.5rem 0;">
            <input type="checkbox" name="embeddings_native_consent" value="1" @checked(old('embeddings_native_consent', $settings['embeddings_native_consent']) === true || old('embeddings_native_consent', $settings['embeddings_native_consent']) === '1')>
            Ik geef expliciet toestemming dat afgeleiden en zoektekst naar de gekozen native embeddingsprovider worden verstuurd. Bij OpenRouter routeert de zoektekst via een door mij gekozen upstream-model met diens eigen privacy-/retentiebeleid, dat deze app niet kan afdwingen.
        </label>
        <p>Een modelwissel (andere provider, model of dimensies) vereist een nieuwe indexgeneratie. Bestaande en nieuwe embeddings worden nooit in dezelfde vectorruimte gemengd.</p>

        <h2>Endpoints en privacy</h2>
        <label>Lokale/eigen endpoint
            <input type="url" name="local_endpoint" value="{{ old('local_endpoint', $settings['local_endpoint']) }}" placeholder="http://ai-service:8080 of https://ai.example.org">
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
        <p>API-sleutels worden hierboven ingesteld/vervangen of expliciet verwijderd; ze worden versleuteld opgeslagen en nooit ingevuld in een formulier.</p>
        <button type="submit">AI-instellingen opslaan</button>
    </form>

    <form method="POST" action="{{ route('admin.operations.ai.analyze') }}" class="card">
        @csrf
        <h2>Beeldanalyse starten</h2>
        <p>Analyseert de geselecteerde, gescande primaire bestanden. Elke run levert menselijk te beoordelen suggesties op (accepteren/afwijzen) en wijzigt nooit rechtstreeks een asset. Volg de voortgang, en stop of herstart indien nodig, op de <a href="{{ route('admin.operations.runs.index') }}">runs-pagina</a>.</p>
        <label>Asset-id's
            <textarea name="asset_ids" rows="3" placeholder="ULID's gescheiden door komma's of regels"></textarea>
        </label>
        <label>Provider
            <select name="provider">
                @foreach(\App\Modules\Ai\Services\AiConfigurationService::IMAGE_ANALYSIS_PROVIDERS as $provider)
                    <option value="{{ $provider }}" @selected($settings['image_analysis_provider'] === $provider)>{{ ucfirst($provider) }}</option>
                @endforeach
            </select>
        </label>
        @unless($settings['image_analysis_ready'])
            <p role="alert">Beeldanalyse is nog niet gereed: kies hierboven een provider/model en geef toestemming.</p>
        @endunless
        <button type="submit" @disabled(! $settings['image_analysis_ready'])>Beeldanalyse starten</button>
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
                @foreach(\App\Modules\Ai\Services\AiConfigurationService::EMBEDDINGS_PROVIDERS as $provider)
                    <option value="{{ $provider }}" @selected($settings['embeddings_provider'] === $provider)>{{ ucfirst($provider) }}</option>
                @endforeach
            </select>
        </label>
        @unless($settings['embeddings_ready'])
            <p role="alert">Embeddings zijn nog niet gereed: kies hierboven een provider/model en geef toestemming.</p>
        @endunless
        <button type="submit" @disabled(! $settings['embeddings_ready'])>Embeddingindex starten</button>
    </form>
@endsection
