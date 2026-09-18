@extends('layouts.app')
@section('title', 'OCR Tekstherkenning - Vistora Operaties')
@section('content')
    @include('operations._nav')
    <p class="eyebrow">{{ __('operations.generated.t_a70fa03342889655') }}</p>
    <h1>{{ __('operations.generated.t_c376b363af0d22aa') }}</h1>
    <p class="intro">{{ __('operations.generated.t_b8c11dea97fd92be') }}</p>

    {{-- Diagnostiek Card --}}
    <section class="card" style="margin-bottom: 2rem;">
        <h2>{{ __('operations.generated.t_4e9196a03d483453') }}</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem;">
            <div>
                <strong>Configuratiestatus:</strong>
                <p>
                    @if($diagnostics['enabled'])
                        <span style="color: #059669; font-weight: bold;">{{ __('operations.generated.t_a94da4f43c16de24') }}</span>
                    @else
                        <span style="color: #dc2626; font-weight: bold;">{{ __('operations.generated.t_1e86c0fb71bbfdc5') }}</span>
                    @endif
                </p>
            </div>
            <div>
                <strong>{{ __('operations.generated.t_da836c86561bd59c') }}</strong>
                <p>
                    @if($diagnostics['available'])
                        <span style="color: #059669; font-weight: bold;">Beschikbaar</span>
                    @else
                        <span style="color: #d97706; font-weight: bold;">{{ __('operations.generated.t_021d08e92e4b3adc') }}</span>
                    @endif
                </p>
            </div>
            <div>
                <strong>{{ __('operations.generated.t_3598ad25e588427a') }}</strong>
                <p>{{ $diagnostics['version'] ?? 'Geen executable gevonden' }}</p>
            </div>
            <div>
                <strong>Talen:</strong>
                <p>{{ !empty($diagnostics['languages']) ? implode(', ', $diagnostics['languages']) : '-' }}</p>
            </div>
        </div>
        <p style="margin-top: 0.5rem; font-size: 0.875rem; color: #4b5563;">{{ $diagnostics['status_message'] }}</p>
    </section>

    {{-- Zoekbalk --}}
    <section class="card" style="margin-bottom: 2rem;">
        <h2>{{ __('operations.generated.t_2d2bb01619bb1043') }}</h2>
        <form method="get" action="{{ route('admin.operations.ocr.index') }}" style="display: flex; gap: 0.5rem; margin-top: 1rem;">
            <input type="text" name="q" value="{{ $queryString ?? '' }}" placeholder="{{ __('operations.generated.t_8dee5dc02b65ff46') }}" style="flex: 1; padding: 0.5rem;">
            <button type="submit" class="button">Zoeken</button>
            @if($queryString)
                <a href="{{ route('admin.operations.ocr.index') }}" class="button secondary" style="text-decoration: none; align-content: center;">Wissen</a>
            @endif
        </form>
    </section>

    {{-- Resultatenlijst --}}
    <section class="card">
        <h2>{{ __('operations.generated.t_a3157a7322e183b4') }}{{ $ocrRecords->total() }})</h2>
        @if($ocrRecords->isEmpty())
            <p>{{ __('operations.generated.t_a2ab9823f8ec3bc5') }}</p>
        @else
            {{-- Tabellen mogen op een telefoon van 390 px de pagina niet zijwaarts laten schuiven. --}}
<div class="ops-table-scroll" style="overflow-x: auto; max-width: 100%;"><table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
                <thead>
                    <tr style="text-align: left; border-bottom: 2px solid #e5e7eb;">
                        <th style="padding: 0.75rem;">Aanwinstnr</th>
                        <th style="padding: 0.75rem;">Titel</th>
                        <th style="padding: 0.75rem;">Status</th>
                        <th style="padding: 0.75rem;">{{ __('operations.generated.t_206cae1ea77a0db7') }}</th>
                        <th style="padding: 0.75rem;">Bewerkt?</th>
                        <th style="padding: 0.75rem;">Actie</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($ocrRecords as $rec)
                        <tr style="border-bottom: 1px solid #e5e7eb;">
                            <td style="padding: 0.75rem;"><strong>{{ $rec->asset?->accession_number ?? '-' }}</strong></td>
                            <td style="padding: 0.75rem;">{{ $rec->asset?->title ?? '-' }}</td>
                            <td style="padding: 0.75rem;">
                                @if($rec->status === 'completed')
                                    <span style="color: #059669; font-weight: bold;">Voltooid</span>
                                @elseif($rec->status === 'failed')
                                    <span style="color: #dc2626; font-weight: bold;">Mislukt</span>
                                @elseif($rec->status === 'disabled')
                                    <span style="color: #4b5563;">Uitgeschakeld</span>
                                @else
                                    <span>{{ $rec->status }}</span>
                                @endif
                            </td>
                            <td style="padding: 0.75rem; font-size: 0.875rem; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                {{ $rec->getEffectiveText() ?: '(geen tekst herkend)' }}
                            </td>
                            <td style="padding: 0.75rem;">
                                @if($rec->is_edited)
                                    <span style="color: #2563eb; font-weight: bold;">{{ __('operations.generated.t_59c6040bf3ded0a7') }}</span>
                                @else
                                    <span style="color: #4b5563;">Machine-gegenereerd</span>
                                @endif
                            </td>
                            <td style="padding: 0.75rem;">
                                <a href="{{ route('admin.operations.ocr.show', $rec) }}" class="button" style="padding: 4px 8px; font-size: 0.8rem; text-decoration: none;">{{ __('operations.generated.t_0341da55b5ee325c') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>

            <div style="margin-top: 1rem;">
                {{ $ocrRecords->links() }}
            </div>
        @endif
    </section>
@endsection
