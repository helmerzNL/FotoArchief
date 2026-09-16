@extends('layouts.app')
@section('title', 'Duplicaat Vergelijken & Koppelen - FotoArchief')
@section('content')
    <p class="eyebrow"><a href="{{ route('admin.operations.duplicates.index') }}">&larr; Terug naar duplicaten</a></p>
    <h1>Duplicaat Dossier Vergelijken</h1>
    <p class="intro">Vergelijk de upload met het bestaande archiefbestand en koppel eventuele nieuwe herkomstinformatie aan het bestaande dossier.</p>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 1.5rem;">
        {{-- Nieuwe duplicaat upload --}}
        <section class="card" style="border: 2px dashed #d1d5db;">
            <h2>Nieuwe Upload (Duplicaat)</h2>
            <p><strong>Bestandsnaam:</strong> {{ $upload->original_filename }}</p>
            <p><strong>Grootte:</strong> {{ number_format($upload->byte_size / 1024, 1) }} KB</p>
            <p><strong>Geüpload door:</strong> {{ $upload->uploadedBy?->name ?? 'Onbekend' }}</p>
            <p><strong>Geüpload op:</strong> {{ $upload->created_at?->format('d-m-Y H:i:s') }}</p>
            <p><strong>Status:</strong> <span style="color: #d97706; font-weight: bold;">Afgewezen wegens duplicaat</span></p>
            <p><strong>SHA-256:</strong> <code style="font-size: 0.75rem; word-break: break-all;">{{ $upload->detected_sha256 }}</code></p>
        </section>

        {{-- Bestaand dossier --}}
        <section class="card" style="border: 2px solid #10b981;">
            <h2>Bestaand Dossier in Archief</h2>
            <p><strong>Nummer:</strong> <a href="{{ route('admin.assets.show', $targetAsset) }}" target="_blank"><strong>{{ $targetAsset->accession_number }}</strong></a></p>
            <p><strong>Titel:</strong> {{ $targetAsset->title }}</p>
            <p><strong>Datering:</strong> {{ $targetAsset->date_display ?: ($targetAsset->date_earliest ? $targetAsset->date_earliest->format('Y-m-d') : 'Onbekend') }}</p>
            <p><strong>Huidige beschrijving:</strong> {{ $targetAsset->description ?: 'Geen beschrijving' }}</p>
            <p><strong>Bestaande tags:</strong> {{ $targetAsset->tags->pluck('name')->join(', ') ?: 'Geen tags' }}</p>
            @if($targetFile)
                <p><strong>Origineel bestand:</strong> {{ $targetFile->original_filename }} ({{ $targetFile->pixel_width }}x{{ $targetFile->pixel_height }} px)</p>
            @endif
        </section>
    </div>

    <section class="card" style="margin-top: 2rem;">
        <h2>Herkomst Verrijken &amp; Duplicaat Koppelen</h2>
        <p>Voeg eventuele aanvullende gegevens van deze upload toe aan het bestaande dossier. Er wordt <strong>geen</strong> tweede afbeeldingsbestand opgeslagen.</p>

        <form method="post" action="{{ route('admin.operations.duplicates.link', $upload) }}" style="margin-top: 1rem;">
            @csrf
            <div style="margin-bottom: 1rem;">
                <label for="provenance_note"><strong>Aanvullende herkomst- / bronnotitie:</strong></label>
                <textarea id="provenance_note" name="provenance_note" rows="4" style="width: 100%;" placeholder="Bijv. Bron: Collectie Jansen, schenking 2026. Bevat aantekening op achterzijde.">{{ old('provenance_note') }}</textarea>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label for="tags"><strong>Extra tags toevoegen (kommagescheiden):</strong></label>
                <input type="text" id="tags" name="tags" style="width: 100%;" value="{{ old('tags') }}" placeholder="dorpsstraat, optocht, 1935">
            </div>

            <button type="submit" class="button">Koppel aan Dossier &amp; Verrijk Herkomst</button>
            <a href="{{ route('admin.operations.duplicates.index') }}" class="secondary" style="margin-left: 1rem;">Annuleren</a>
        </form>
    </section>
@endsection
