@extends('layouts.app')
@section('title', 'OCR Tekst Detail - FotoArchief Operaties')
@section('content')
    @include('operations._nav')
    <p class="eyebrow">{{ __('operations.generated.t_a70fa03342889655') }}</p>
    <h1>{{ __('operations.generated.t_c8b492139dab6ed9') }} {{ $ocr->asset?->accession_number }}</h1>
    <p class="intro">{{ __('operations.generated.t_7f521760d0a13d40') }}</p>

    <div class="grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 1.5rem;">
        {{-- Linkerkolom: Ruwe machine-tekst --}}
        <section class="card">
            <h2>{{ __('operations.generated.t_3eb5f202f67b4f94') }}</h2>
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
            <h2>{{ __('operations.generated.t_80a8f43d8e1aaee6') }}</h2>
            <p style="font-size: 0.875rem; color: #4b5563;">
                @if($ocr->is_edited)
                    <span style="color: #059669; font-weight: bold;">{{ __('operations.generated.t_3a8bb3d54c9921bc') }}</span>
                @else
                    <span>{{ __('operations.generated.t_46f4f94bf07ffa30') }}</span>
                @endif
            </p>

            <form method="post" action="{{ route('admin.operations.ocr.update', $ocr) }}" style="margin-top: 1rem;">
                @csrf
                <div>
                    <label for="edited_text"><strong>Transcriptietekst:</strong></label>
                    <textarea id="edited_text" name="edited_text" rows="14" style="width: 100%; font-family: sans-serif; padding: 0.5rem; margin-top: 0.5rem;" required>{{ $ocr->edited_text ?? $ocr->extracted_text }}</textarea>
                </div>

                <div style="margin-top: 1rem; display: flex; gap: 0.5rem;">
                    <button type="submit" class="button">{{ __('operations.generated.t_d097ec8de2ba53ea') }}</button>
                    <a href="{{ route('admin.operations.ocr.index') }}" class="button secondary" style="text-decoration: none; align-content: center;">{{ __('operations.generated.t_099941b7ad02a47d') }}</a>
                </div>
            </form>
        </section>
    </div>
@endsection
