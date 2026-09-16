@extends('layouts.app')
@section('title', 'OCR Tekstherkenning - FotoArchief Operaties')
@section('content')
    <p class="eyebrow">Operaties &middot; Tekstverwerking</p>
    <h1>Tesseract OCR Tekstherkenning</h1>
    <p class="intro">Achtergrondtekstherkenning voor archiefscans met machine-tekstlabels, archivarissencorrectie en doorzoekbaarheid.</p>

    {{-- Diagnostiek Card --}}
    <section class="card" style="margin-bottom: 2rem;">
        <h2>OCR Engine Status</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem;">
            <div>
                <strong>Configuratiestatus:</strong>
                <p>
                    @if($diagnostics['enabled'])
                        <span style="color: #059669; font-weight: bold;">Ingeschakeld (OCR_ENABLED=true)</span>
                    @else
                        <span style="color: #dc2626; font-weight: bold;">Uitgeschakeld (OCR_ENABLED=false)</span>
                    @endif
                </p>
            </div>
            <div>
                <strong>Executable Status:</strong>
                <p>
                    @if($diagnostics['available'])
                        <span style="color: #059669; font-weight: bold;">Beschikbaar</span>
                    @else
                        <span style="color: #d97706; font-weight: bold;">Niet Beschikbaar</span>
                    @endif
                </p>
            </div>
            <div>
                <strong>Engine Versie:</strong>
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
        <h2>Doorzoek Herkende Archiefteksten</h2>
        <form method="get" action="{{ route('admin.operations.ocr.index') }}" style="display: flex; gap: 0.5rem; margin-top: 1rem;">
            <input type="text" name="q" value="{{ $queryString ?? '' }}" placeholder="Zoek op woorden in machine- of gecorrigeerde tekst..." style="flex: 1; padding: 0.5rem;">
            <button type="submit" class="button">Zoeken</button>
            @if($queryString)
                <a href="{{ route('admin.operations.ocr.index') }}" class="button secondary" style="text-decoration: none; align-content: center;">Wissen</a>
            @endif
        </form>
    </section>

    {{-- Resultatenlijst --}}
    <section class="card">
        <h2>OCR Dossiers &amp; Teksten ({{ $ocrRecords->total() }})</h2>
        @if($ocrRecords->isEmpty())
            <p>Geen OCR-resultaten gevonden.</p>
        @else
            <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
                <thead>
                    <tr style="text-align: left; border-bottom: 2px solid #e5e7eb;">
                        <th style="padding: 0.75rem;">Aanwinstnr</th>
                        <th style="padding: 0.75rem;">Titel</th>
                        <th style="padding: 0.75rem;">Status</th>
                        <th style="padding: 0.75rem;">Machine-tekst Preview</th>
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
                                    <span style="color: #2563eb; font-weight: bold;">Gecorrigeerd door Archivaris</span>
                                @else
                                    <span style="color: #4b5563;">Machine-gegenereerd</span>
                                @endif
                            </td>
                            <td style="padding: 0.75rem;">
                                <a href="{{ route('admin.operations.ocr.show', $rec) }}" class="button" style="padding: 4px 8px; font-size: 0.8rem; text-decoration: none;">Bekijk / Bewerk</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div style="margin-top: 1rem;">
                {{ $ocrRecords->links() }}
            </div>
        @endif
    </section>
@endsection
