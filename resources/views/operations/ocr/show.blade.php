@extends('layouts.app')
@section('title', 'OCR Tekst Detail - FotoArchief Operaties')
@section('content')
    @include('operations._nav')
    <p class="eyebrow">Operaties &middot; Tekstverwerking</p>
    <h1>OCR Tekst Dossier: {{ $ocr->asset?->accession_number }}</h1>
    <p class="intro">Inspecteer de ruwe machine-gegenereerde tekst en sla handmatige transcriptiecorrecties op.</p>

    <div class="grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 1.5rem;">
        {{-- Linkerkolom: Ruwe machine-tekst --}}
        <section class="card">
            <h2>Machine-gegenereerde Tekst (Tesseract)</h2>
            <p style="font-size: 0.875rem; color: #4b5563;">
                <strong>Status:</strong> {{ $ocr->status }} &middot;
                <strong>Taal:</strong> {{ $ocr->language }} &middot;
                <strong>Engine:</strong> {{ $ocr->engine_version ?? '-' }}
            </p>

            <div style="background: #f3f4f6; padding: 1rem; border-radius: 4px; font-family: monospace; white-space: pre-wrap; margin-top: 1rem; min-height: 200px; max-height: 500px; overflow-y: auto;">
                {{ $ocr->extracted_text ?: '(Geen machine-tekst herkend of fout opgetreden)' }}
            </div>

            @if($ocr->error_message)
                <p style="color: #dc2626; margin-top: 0.5rem; font-size: 0.875rem;"><strong>Foutmelding:</strong> {{ $ocr->error_message }}</p>
            @endif
        </section>

        {{-- Rechterkolom: Gecorrigeerde archieftekst bewerken --}}
        <section class="card">
            <h2>Gevalideerde / Gecorrigeerde Tekst</h2>
            <p style="font-size: 0.875rem; color: #4b5563;">
                @if($ocr->is_edited)
                    <span style="color: #059669; font-weight: bold;">Dit dossier bevat een handmatig gecorrigeerde transcriptie.</span>
                @else
                    <span>Nog niet handmatig gecorrigeerd. De machine-tekst wordt gebruikt.</span>
                @endif
            </p>

            <form method="post" action="{{ route('admin.operations.ocr.update', $ocr) }}" style="margin-top: 1rem;">
                @csrf
                <div>
                    <label for="edited_text"><strong>Transcriptietekst:</strong></label>
                    <textarea id="edited_text" name="edited_text" rows="14" style="width: 100%; font-family: sans-serif; padding: 0.5rem; margin-top: 0.5rem;" required>{{ $ocr->edited_text ?? $ocr->extracted_text }}</textarea>
                </div>

                <div style="margin-top: 1rem; display: flex; gap: 0.5rem;">
                    <button type="submit" class="button">Tekstcorrectie Opslaan</button>
                    <a href="{{ route('admin.operations.ocr.index') }}" class="button secondary" style="text-decoration: none; align-content: center;">Terug naar overzicht</a>
                </div>
            </form>
        </section>
    </div>
@endsection
