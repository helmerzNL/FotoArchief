{{-- Shared status panel for background archive operations. --}}
<section class="card" style="margin-top: 2rem;">
    <h2>Achtergrondtaken</h2>
    <p style="font-size: 0.875rem; color: #4b5563;">
        {{ __('operations.generated.t_8a94fe8fea44c546') }}
    </p>

    @if($runs->isEmpty())
        <p>{{ __('operations.generated.t_25a4e800f3e3882d') }}</p>
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
                        <td style="padding: 0.75rem;"><a href="{{ route('admin.operations.runs.show', $run) }}"><code>{{ substr($run->id, 0, 12) }}</code></a></td>
                        <td style="padding: 0.75rem;">{{ $run->operation_type }}</td>
                        <td style="padding: 0.75rem;">
                            @if($run->status === 'completed')
                                <span style="color: #059669; font-weight: bold;">Voltooid</span>
                            @elseif($run->status === 'failed')
                                <span style="color: #dc2626; font-weight: bold;">Mislukt</span>
                            @elseif($run->status === 'running')
                                <span style="color: #2563eb; font-weight: bold;">Bezig</span>
                            @elseif($run->status === 'paused')
                                <span>{{ __('workbench.paused') }}</span>
                            @elseif($run->status === 'cancelled')
                                <span style="color: #6b7280; font-weight: bold;">Geannuleerd</span>
                            @else
                                <span style="color: #d97706; font-weight: bold;">{{ __('operations.generated.t_9b88bb032d925e75') }}</span>
                            @endif
                        </td>
                        <td style="padding: 0.75rem; font-size: 0.875rem;">
                            {{ $run->processed_items }}@if($run->total_items > 0) / {{ $run->total_items }}@endif {{ __('operations.fragments.processed') }}
                            @if($run->failed_items > 0)
                                <span style="color: #dc2626;">({{ $run->failed_items }} {{ __('operations.fragments.failed') }})</span>
                            @endif
                        </td>
                        <td style="padding: 0.75rem; font-size: 0.875rem; color: #dc2626; max-width: 320px;">
                            {{ $run->error_message ?? '-' }}
                            @if($run->auditEvents->isNotEmpty())
                                <details style="margin-top: 0.5rem; color: #374151;">
                                    <summary>{{ __('operations.generated.t_dab34c3526adb141') }}{{ $run->auditEvents->count() }})</summary>
                                    <ol style="padding-left: 1.25rem;">
                                        @foreach($run->auditEvents as $event)
                                            <li style="margin-top: 0.35rem;">
                                                <strong>{{ $event->created_at }}</strong> · {{ $event->event_type }}
                                                @if($event->message)<br>{{ $event->message }}@endif
                                                @if($event->context)
                                                    <details><summary>{{ __('operations.generated.t_21ed4703815d0981') }}</summary><pre class="revision">{{ json_encode($event->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ol>
                                </details>
                            @endif
                        </td>
                        <td style="padding: 0.75rem;">
                            @if(in_array($run->operation_type, ['ai.analysis', 'ai.index'], true))
                                @can('assets.view')
                                    <p><a href="{{ route('admin.operations.runs.ai-results', $run) }}">{{ __('operations.generated.t_04df4d525e4e9fe5') }}</a></p>
                                @endcan
                            @endif
                            @if($run->status === 'failed')
                                <form method="post" action="{{ route('admin.operations.runs.retry', $run) }}">
                                    @csrf
                                    <button type="submit" class="button" style="padding: 4px 8px; font-size: 0.8rem;">{{ __('operations.generated.t_e05ea9918232c0b5') }}</button>
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
