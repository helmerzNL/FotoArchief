@extends('layouts.app')
@section('title', 'Verwerkingscentrum - Vistora Operaties')
@section('content')
    @include('operations._nav')
    <p class="eyebrow">{{ __('operations.generated.t_19dde8a3c6f6b4e7') }}</p>
    <h1>Verwerkingscentrum</h1>
    <p class="intro">{{ __('operations.generated.t_7e80220b5555b373') }}</p>

    {{-- Statistieken kaarten --}}
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 140px), 1fr)); gap: 1rem; margin-bottom: 2rem;">
        <div class="card" style="text-align: center;">
            <div style="font-size: 1.75rem; font-weight: bold;">{{ $stats['total'] }}</div>
            <div style="font-size: 0.875rem; color: var(--muted);">Totaal</div>
        </div>
        <div class="card" style="text-align: center;">
            <div style="font-size: 1.75rem; font-weight: bold; color: var(--color-info);">{{ $stats['queued'] }}</div>
            <div style="font-size: 0.875rem; color: var(--muted);">{{ __('operations.generated.t_9b88bb032d925e75') }}</div>
        </div>
        <div class="card" style="text-align: center;">
            <div style="font-size: 1.75rem; font-weight: bold; color: var(--color-warning);">{{ $stats['running'] }}</div>
            <div style="font-size: 0.875rem; color: var(--muted);">Bezig</div>
        </div>
        <div class="card" style="text-align: center;">
            <div style="font-size: 1.75rem; font-weight: bold; color: var(--color-error);">{{ $stats['failed'] }}</div>
            <div style="font-size: 0.875rem; color: var(--muted);">Mislukt</div>
        </div>
        <div class="card" style="text-align: center;">
            <div style="font-size: 1.75rem; font-weight: bold; color: var(--color-success);">{{ $stats['completed'] }}</div>
            <div style="font-size: 0.875rem; color: var(--muted);">Voltooid</div>
        </div>
        <div class="card" style="text-align: center;">
            <div style="font-size: 1.75rem; font-weight: bold; color: var(--color-secondary);">{{ $stats['stale'] }}</div>
            <div style="font-size: 0.875rem; color: var(--muted);">{{ __('operations.generated.t_2eb81328cadfb9b8') }}</div>
        </div>
    </div>

    {{-- Filter navigatie & Actieknoppen --}}
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <a class="button @if($status === 'all') primary @else secondary @endif" href="{{ route('admin.operations.processing.index', ['status' => 'all']) }}">Alle</a>
            <a class="button @if($status === 'queued') primary @else secondary @endif" href="{{ route('admin.operations.processing.index', ['status' => 'queued']) }}">{{ __('operations.generated.t_5edea7ebb6893816') }}{{ $stats['queued'] }})</a>
            <a class="button @if($status === 'running') primary @else secondary @endif" href="{{ route('admin.operations.processing.index', ['status' => 'running']) }}">{{ __('operations.generated.t_9e5f48c8f3dd514c') }}{{ $stats['running'] }})</a>
            <a class="button @if($status === 'failed') primary @else secondary @endif" href="{{ route('admin.operations.processing.index', ['status' => 'failed']) }}">{{ __('operations.generated.t_d298519cf95a214b') }}{{ $stats['failed'] }})</a>
            <a class="button @if($status === 'stale') primary @else secondary @endif" href="{{ route('admin.operations.processing.index', ['status' => 'stale']) }}">{{ __('operations.generated.t_6764909fea11960e') }}{{ $stats['stale'] }})</a>
            <a class="button @if($status === 'rejected') primary @else secondary @endif" href="{{ route('admin.operations.processing.index', ['status' => 'rejected']) }}">{{ __('operations.generated.t_d232ff7977274d7e') }}{{ $stats['rejected'] }})</a>
            <a class="button @if($status === 'completed') primary @else secondary @endif" href="{{ route('admin.operations.processing.index', ['status' => 'completed']) }}">{{ __('operations.generated.t_9a0a5a9aacec33f2') }}{{ $stats['completed'] }})</a>
        </div>

        @if($stats['failed'] > 0)
            <form method="post" action="{{ route('admin.operations.processing.retryAll') }}">
                @csrf
                <button type="submit" class="button" style="background-color: var(--color-warning);">{{ __('operations.generated.t_c3b000ac4506590b') }}{{ $stats['failed'] }})</button>
            </form>
        @endif
    </div>

    {{-- Takenoverzicht --}}
    @if($uploads->isEmpty())
        <div class="notice">
            <p>{{ __('operations.generated.t_5edb47fd5082c4e5') }}</p>
        </div>
    @else
        {{-- Tabellen mogen op een telefoon van 390 px de pagina niet zijwaarts laten schuiven. --}}
<div class="ops-table-scroll" style="overflow-x: auto; max-width: 100%;"><table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; border-bottom: 2px solid var(--border);">
                    <th style="padding: 0.75rem;">{{ __('operations.generated.t_37f85bcbd3924726') }}</th>
                    <th style="padding: 0.75rem;">Dossier</th>
                    <th style="padding: 0.75rem;">Status</th>
                    <th style="padding: 0.75rem;">Pogingen</th>
                    <th style="padding: 0.75rem;">{{ __('operations.generated.t_012d44863eac0f9d') }}</th>
                    <th style="padding: 0.75rem;">Tijdstip</th>
                    <th style="padding: 0.75rem;">Actie</th>
                </tr>
            </thead>
            <tbody>
                @foreach($uploads as $upload)
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td style="padding: 0.75rem;">
                            <strong>{{ $upload->original_filename }}</strong><br>
                            <span style="font-size: 0.75rem; color: var(--muted);">{{ $upload->id }} &middot; {{ number_format($upload->byte_size / 1024, 1) }} {{ __('operations.fragments.kilobytes') }}</span>
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
                                <span style="color: var(--color-success); font-weight: bold;">Voltooid</span>
                            @elseif($upload->status === 'running')
                                <span style="color: var(--color-warning); font-weight: bold;">Bezig</span>
                            @elseif($upload->status === 'failed')
                                <span style="color: var(--color-error); font-weight: bold;">Mislukt</span>
                            @elseif($upload->status === 'rejected')
                                <span style="color: var(--muted); font-weight: bold;">Afgewezen</span>
                            @else
                                <span style="color: var(--color-info); font-weight: bold;">{{ __('operations.generated.t_9b88bb032d925e75') }}</span>
                            @endif
                        </td>
                        <td style="padding: 0.75rem;">{{ $upload->attempts }}</td>
                        <td style="padding: 0.75rem; font-size: 0.875rem; max-width: 300px;">
                            @if($upload->failure_reason)
                                <span style="color: var(--color-error);">{{ Str::limit($upload->failure_reason, 80) }}</span>
                            @else
                                <span style="color: var(--muted);">-</span>
                            @endif
                        </td>
                        <td style="padding: 0.75rem; font-size: 0.875rem;">{{ $upload->created_at?->format('d-m-Y H:i') }}</td>
                        <td style="padding: 0.75rem;">
                            <div style="display: flex; gap: 0.5rem;">
                                <a class="button secondary" style="padding: 4px 8px; font-size: 0.8rem;" href="{{ route('admin.operations.processing.show', $upload) }}">Details</a>
                                @if(in_array($upload->status, ['failed', 'rejected']) || ($upload->status === 'running' && $upload->started_at?->lt(now()->subMinutes(4))))
                                    <form method="post" action="{{ route('admin.operations.processing.retry', $upload) }}">
                                        @csrf
                                        <button type="submit" class="secondary" style="padding: 4px 8px; font-size: 0.8rem; background: var(--color-warning-surface);">Herstart</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>

        <div style="margin-top: 1.5rem;">
            {{ $uploads->links() }}
        </div>
    @endif
@endsection
