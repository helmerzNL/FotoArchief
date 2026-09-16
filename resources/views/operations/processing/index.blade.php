@extends('layouts.app')
@section('title', 'Verwerkingscentrum - FotoArchief Operaties')
@section('content')
    <p class="eyebrow">Operaties &middot; Achtergrondverwerking</p>
    <h1>Verwerkingscentrum</h1>
    <p class="intro">Realtime overzicht van alle achtergrondtaken, wachtrijen, mislukte opnames en herstelmogelijkheden.</p>

    {{-- Statistieken kaarten --}}
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
        <div class="card" style="text-align: center;">
            <div style="font-size: 1.75rem; font-weight: bold;">{{ $stats['total'] }}</div>
            <div style="font-size: 0.875rem; color: #6b7280;">Totaal</div>
        </div>
        <div class="card" style="text-align: center;">
            <div style="font-size: 1.75rem; font-weight: bold; color: #2563eb;">{{ $stats['queued'] }}</div>
            <div style="font-size: 0.875rem; color: #6b7280;">In wachtrij</div>
        </div>
        <div class="card" style="text-align: center;">
            <div style="font-size: 1.75rem; font-weight: bold; color: #d97706;">{{ $stats['running'] }}</div>
            <div style="font-size: 0.875rem; color: #6b7280;">Bezig</div>
        </div>
        <div class="card" style="text-align: center;">
            <div style="font-size: 1.75rem; font-weight: bold; color: #dc2626;">{{ $stats['failed'] }}</div>
            <div style="font-size: 0.875rem; color: #6b7280;">Mislukt</div>
        </div>
        <div class="card" style="text-align: center;">
            <div style="font-size: 1.75rem; font-weight: bold; color: #059669;">{{ $stats['completed'] }}</div>
            <div style="font-size: 0.875rem; color: #6b7280;">Voltooid</div>
        </div>
        <div class="card" style="text-align: center;">
            <div style="font-size: 1.75rem; font-weight: bold; color: #7c3aed;">{{ $stats['stale'] }}</div>
            <div style="font-size: 0.875rem; color: #6b7280;">Vastgelopen (&gt;4m)</div>
        </div>
    </div>

    {{-- Filter navigatie & Actieknoppen --}}
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <a class="button @if($status === 'all') primary @else secondary @endif" href="{{ route('admin.operations.processing.index', ['status' => 'all']) }}">Alle</a>
            <a class="button @if($status === 'queued') primary @else secondary @endif" href="{{ route('admin.operations.processing.index', ['status' => 'queued']) }}">In wachtrij ({{ $stats['queued'] }})</a>
            <a class="button @if($status === 'running') primary @else secondary @endif" href="{{ route('admin.operations.processing.index', ['status' => 'running']) }}">Bezig ({{ $stats['running'] }})</a>
            <a class="button @if($status === 'failed') primary @else secondary @endif" href="{{ route('admin.operations.processing.index', ['status' => 'failed']) }}">Mislukt ({{ $stats['failed'] }})</a>
            <a class="button @if($status === 'stale') primary @else secondary @endif" href="{{ route('admin.operations.processing.index', ['status' => 'stale']) }}">Vastgelopen ({{ $stats['stale'] }})</a>
            <a class="button @if($status === 'rejected') primary @else secondary @endif" href="{{ route('admin.operations.processing.index', ['status' => 'rejected']) }}">Afgewezen ({{ $stats['rejected'] }})</a>
            <a class="button @if($status === 'completed') primary @else secondary @endif" href="{{ route('admin.operations.processing.index', ['status' => 'completed']) }}">Voltooid ({{ $stats['completed'] }})</a>
        </div>

        @if($stats['failed'] > 0)
            <form method="post" action="{{ route('admin.operations.processing.retryAll') }}">
                @csrf
                <button type="submit" class="button" style="background-color: #d97706;">Herprobeer Alle Mislukte ({{ $stats['failed'] }})</button>
            </form>
        @endif
    </div>

    {{-- Takenoverzicht --}}
    @if($uploads->isEmpty())
        <div class="notice">
            <p>Geen verwerkingstaken gevonden voor de geselecteerde filter status.</p>
        </div>
    @else
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; border-bottom: 2px solid #e5e7eb;">
                    <th style="padding: 0.75rem;">Taak ID / Bestand</th>
                    <th style="padding: 0.75rem;">Dossier</th>
                    <th style="padding: 0.75rem;">Status</th>
                    <th style="padding: 0.75rem;">Pogingen</th>
                    <th style="padding: 0.75rem;">Foutmelding / Reden</th>
                    <th style="padding: 0.75rem;">Tijdstip</th>
                    <th style="padding: 0.75rem;">Actie</th>
                </tr>
            </thead>
            <tbody>
                @foreach($uploads as $upload)
                    <tr style="border-bottom: 1px solid #e5e7eb;">
                        <td style="padding: 0.75rem;">
                            <strong>{{ $upload->original_filename }}</strong><br>
                            <span style="font-size: 0.75rem; color: #6b7280;">{{ $upload->id }} &middot; {{ number_format($upload->byte_size / 1024, 1) }} KB</span>
                        </td>
                        <td style="padding: 0.75rem;">
                            @if($upload->asset)
                                <a href="{{ route('admin.assets.show', $upload->asset) }}">{{ $upload->asset->accession_number }}</a>
                            @else
                                -
                            @endif
                        </td>
                        <td style="padding: 0.75rem;">
                            @if($upload->status === 'completed')
                                <span style="color: #059669; font-weight: bold;">Voltooid</span>
                            @elseif($upload->status === 'running')
                                <span style="color: #d97706; font-weight: bold;">Bezig</span>
                            @elseif($upload->status === 'failed')
                                <span style="color: #dc2626; font-weight: bold;">Mislukt</span>
                            @elseif($upload->status === 'rejected')
                                <span style="color: #6b7280; font-weight: bold;">Afgewezen</span>
                            @else
                                <span style="color: #2563eb; font-weight: bold;">In wachtrij</span>
                            @endif
                        </td>
                        <td style="padding: 0.75rem;">{{ $upload->attempts }}</td>
                        <td style="padding: 0.75rem; font-size: 0.875rem; max-width: 300px;">
                            @if($upload->failure_reason)
                                <span style="color: #dc2626;">{{ Str::limit($upload->failure_reason, 80) }}</span>
                            @else
                                <span style="color: #6b7280;">-</span>
                            @endif
                        </td>
                        <td style="padding: 0.75rem; font-size: 0.875rem;">{{ $upload->created_at?->format('d-m-Y H:i') }}</td>
                        <td style="padding: 0.75rem;">
                            <div style="display: flex; gap: 0.5rem;">
                                <a class="button secondary" style="padding: 4px 8px; font-size: 0.8rem;" href="{{ route('admin.operations.processing.show', $upload) }}">Details</a>
                                @if(in_array($upload->status, ['failed', 'rejected']) || ($upload->status === 'running' && $upload->started_at?->lt(now()->subMinutes(4))))
                                    <form method="post" action="{{ route('admin.operations.processing.retry', $upload) }}">
                                        @csrf
                                        <button type="submit" class="secondary" style="padding: 4px 8px; font-size: 0.8rem; background: #fef3c7;">Herstart</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div style="margin-top: 1.5rem;">
            {{ $uploads->links() }}
        </div>
    @endif
@endsection
