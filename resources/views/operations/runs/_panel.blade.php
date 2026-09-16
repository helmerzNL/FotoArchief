{{-- Shared status panel for background archive operations. --}}
<section class="card" style="margin-top: 2rem;">
    <h2>Achtergrondtaken</h2>
    <p style="font-size: 0.875rem; color: #4b5563;">
        Controles, herbouw, opslagkopieën en definitieve verwijderingen draaien op de ingest-wachtrij. Deze pagina toont status, fouten en herpogingen.
    </p>

    @if($runs->isEmpty())
        <p>Nog geen achtergrondtaken uitgevoerd.</p>
    @else
        {{-- Tabellen mogen op een telefoon van 390 px de pagina niet zijwaarts laten schuiven. --}}
<div class="ops-table-scroll" style="overflow-x: auto; max-width: 100%;"><table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 2px solid #e5e7eb;">
                    <th style="padding: 0.75rem;">Taak</th>
                    <th style="padding: 0.75rem;">Type</th>
                    <th style="padding: 0.75rem;">Status</th>
                    <th style="padding: 0.75rem;">Voortgang</th>
                    <th style="padding: 0.75rem;">Foutmelding</th>
                    <th style="padding: 0.75rem;">Actie</th>
                </tr>
            </thead>
            <tbody>
                @foreach($runs as $run)
                    <tr style="border-bottom: 1px solid #e5e7eb;">
                        <td style="padding: 0.75rem;"><code>{{ substr($run->id, 0, 12) }}</code></td>
                        <td style="padding: 0.75rem;">{{ $run->operation_type }}</td>
                        <td style="padding: 0.75rem;">
                            @if($run->status === 'completed')
                                <span style="color: #059669; font-weight: bold;">Voltooid</span>
                            @elseif($run->status === 'failed')
                                <span style="color: #dc2626; font-weight: bold;">Mislukt</span>
                            @elseif($run->status === 'running')
                                <span style="color: #2563eb; font-weight: bold;">Bezig</span>
                            @elseif($run->status === 'cancelled')
                                <span style="color: #6b7280; font-weight: bold;">Geannuleerd</span>
                            @else
                                <span style="color: #d97706; font-weight: bold;">In wachtrij</span>
                            @endif
                        </td>
                        <td style="padding: 0.75rem; font-size: 0.875rem;">
                            {{ $run->processed_items }}@if($run->total_items > 0) / {{ $run->total_items }}@endif verwerkt
                            @if($run->failed_items > 0)
                                <span style="color: #dc2626;">({{ $run->failed_items }} mislukt)</span>
                            @endif
                        </td>
                        <td style="padding: 0.75rem; font-size: 0.875rem; color: #dc2626; max-width: 320px;">
                            {{ $run->error_message ?? '-' }}
                        </td>
                        <td style="padding: 0.75rem;">
                            @if($run->status === 'failed')
                                <form method="post" action="{{ route('admin.operations.runs.retry', $run) }}">
                                    @csrf
                                    <button type="submit" class="button" style="padding: 4px 8px; font-size: 0.8rem;">Opnieuw proberen</button>
                                </form>
                            @elseif($run->status === 'queued')
                                <form method="post" action="{{ route('admin.operations.runs.cancel', $run) }}" onsubmit="return confirm('Deze taak annuleren voordat deze start?');">
                                    @csrf
                                    <button type="submit" class="button secondary" style="padding: 4px 8px; font-size: 0.8rem;">Stoppen</button>
                                </form>
                            @else
                                <span style="color: #4b5563; font-size: 0.875rem;">-</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    @endif
</section>
