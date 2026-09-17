<section class="card" id="ai-results">
    <h2>AI-resultaten</h2>
    <p>Opgeslagen resultaten, nieuwste eerst. Bekijken verstuurt niets naar een provider. Beschrijvingen en tags zijn voorstellen: er verandert geen metadata zonder jouw acceptatie.</p>
    @forelse($aiRuns as $aiRun)
        <article style="border-block-end: 1px solid var(--border); padding-block: 1rem;">
            <h3>{{ $aiRun->run_type === 'embedding' ? 'Zoekindex (embedding)' : 'Beeldanalyse' }}</h3>
            <p>Provider: {{ $aiRun->provider_kind }} · Model: {{ $aiRun->model_id }}@if($aiRun->model_version) ({{ $aiRun->model_version }})@endif</p>
            <p>Opgeslagen: {{ $aiRun->finished_at ?? $aiRun->created_at }} · Status:
                {{ ['succeeded' => 'Geslaagd', 'failed' => 'Mislukt', 'queued' => 'In wachtrij', 'running' => 'Bezig', 'cancelled' => 'Geannuleerd'][$aiRun->status] ?? $aiRun->status }}
            </p>
            <p>Bronrevisie {{ $aiRun->source_asset_lock_version }} · huidige revisie {{ $asset->lock_version }}</p>
            @if($aiRun->source_asset_lock_version !== $asset->lock_version)
                <p>De foto is sinds deze analyse gewijzigd. Voorstellen kunnen verouderd zijn; acceptatie controleert de bron opnieuw.</p>
            @endif
            @if($aiRun->run_type === 'embedding')
                <p>Een embedding is een zoekvector, geen beschrijving of tags. De opgeslagen vector wordt niet als tekstvoorstel getoond.</p>
                <p>Modelruimte: {{ $aiRun->model_space }} · Dimensies: {{ $aiRun->result_summary['dimensions'] ?? 'Niet gerapporteerd' }}</p>
            @else
                @forelse($aiRun->suggestions as $suggestion)
                    <div style="margin-block: 1rem; overflow-wrap: anywhere;">
                        <h4>{{ ['description' => 'Voorgestelde beschrijving', 'tag' => 'Voorgestelde tag', 'object' => 'Voorgesteld object'][$suggestion->suggestion_type] ?? $suggestion->suggestion_type }}</h4>
                        <p style="white-space: pre-wrap;">{{ $suggestion->value }}</p>
                        <p>Beoordeling: <strong>{{ ['pending' => 'Te beoordelen', 'accepted' => 'Geaccepteerd', 'rejected' => 'Afgewezen', 'superseded' => 'Verouderd'][$suggestion->review_status] ?? $suggestion->review_status }}</strong>
                            @if($suggestion->reviewed_at) · {{ $suggestion->reviewed_at }}@endif
                        </p>
                        @if($suggestion->review_note)<p>Beoordelingsnotitie: {{ $suggestion->review_note }}</p>@endif
                        @can('update', $asset)
                            @if($suggestion->review_status === 'pending')
                                @if(in_array($suggestion->suggestion_type, ['description', 'tag'], true))
                                    <form method="post" action="{{ route('admin.operations.ai.suggestions.accept', $suggestion) }}">
                                        @csrf
                                        <input type="hidden" name="return_to" value="asset">
                                        <input type="hidden" name="lock_version" value="{{ $asset->lock_version }}">
                                        <button>Voorstel accepteren</button>
                                    </form>
                                @endif
                                <form method="post" action="{{ route('admin.operations.ai.suggestions.reject', $suggestion) }}">
                                    @csrf
                                    <input type="hidden" name="return_to" value="asset">
                                    <label>Afwijsreden (optioneel) <input name="review_note" maxlength="500"></label>
                                    <button class="secondary">Voorstel afwijzen</button>
                                </form>
                            @endif
                        @endcan
                    </div>
                @empty
                    <p>{{ $aiRun->status === 'succeeded' ? 'Geen beschrijving of tags teruggegeven voor deze analyse.' : 'Nog geen opgeslagen voorstellen voor deze analyse.' }}</p>
                @endforelse
            @endif
        </article>
    @empty
        <p>Nog geen opgeslagen AI-resultaten voor deze foto. Wacht bij een lopende taak op de worker en vernieuw de pagina.</p>
    @endforelse
    {{ $aiRuns->links() }}
</section>
