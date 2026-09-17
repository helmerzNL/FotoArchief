@extends('layouts.app')
@section('title', 'Duplicaat Vergelijken & Koppelen - FotoArchief')
@section('content')
    @include('operations._nav')
    <p class="eyebrow"><a href="{{ route('admin.operations.duplicates.index') }}">{{ __('operations.generated.t_7cc9180df13d2af4') }}</a></p>
    <h1>{{ __('operations.generated.t_59cbffbbbf1c9535') }}</h1>
    <p class="intro">{{ __('operations.generated.t_f8443ba31945fa60') }}</p>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 1.5rem;">
        {{-- Nieuwe duplicaat upload --}}
        <section class="card" style="border: 2px dashed #d1d5db;">
            <h2>{{ __('operations.generated.t_f3f514bd279cacd5') }}</h2>
            <p><strong>Bestandsnaam:</strong> {{ $upload->original_filename }}</p>
            <p><strong>Grootte:</strong> {{ number_format($upload->byte_size / 1024, 1) }} KB</p>
            <p><strong>{{ __('operations.generated.t_d4cdd72bedd54eee') }}</strong> {{ $upload->uploadedBy?->name ?? 'Onbekend' }}</p>
            <p><strong>{{ __('operations.generated.t_cf212c74452036f8') }}</strong> {{ $upload->created_at?->format('d-m-Y H:i:s') }}</p>
            <p><strong>Status:</strong> <span style="color: #d97706; font-weight: bold;">{{ __('operations.generated.t_323f4dda332d8090') }}</span></p>
            <p><strong>SHA-256:</strong> <code style="font-size: 0.75rem; word-break: break-all;">{{ $upload->detected_sha256 }}</code></p>
        </section>

        {{-- Bestaand dossier --}}
        <section class="card" style="border: 2px solid #10b981;">
            <h2>{{ __('operations.generated.t_dd33982a8ff6a501') }}</h2>
            <p><strong>Nummer:</strong> <a href="{{ route('admin.assets.show', $targetAsset) }}" target="_blank"><strong>{{ $targetAsset->accession_number }}</strong></a></p>
            <p><strong>Titel:</strong> {{ $targetAsset->title }}</p>
            <p><strong>Datering:</strong> {{ $targetAsset->date_display ?: ($targetAsset->date_earliest ? $targetAsset->date_earliest->format('Y-m-d') : 'Onbekend') }}</p>
            <p><strong>{{ __('operations.generated.t_b453df4901276c83') }}</strong> {{ $targetAsset->description ?: 'Geen beschrijving' }}</p>
            <p><strong>{{ __('operations.generated.t_0e029734987a26e3') }}</strong> {{ $targetAsset->tags->pluck('name')->join(', ') ?: 'Geen tags' }}</p>
            @if($targetFile)
                <p><strong>{{ __('operations.generated.t_377fd4a0b7db1a8b') }}</strong> {{ $targetFile->original_filename }} ({{ $targetFile->pixel_width }}{{ __('operations.fragments.dimension_separator') }}{{ $targetFile->pixel_height }} {{ __('operations.fragments.pixels') }})</p>
            @endif
        </section>
    </div>

    <section class="card" style="margin-top: 2rem;">
        <h2>{{ __('operations.generated.t_b8afaf4dda36b652') }}</h2>
        <p>{{ __('operations.generated.t_ebc44a48e135c249') }} <strong>geen</strong> {{ __('operations.generated.t_f224d9153c8ef39d') }}</p>

        <form method="post" action="{{ route('admin.operations.duplicates.link', $upload) }}" style="margin-top: 1rem;">
            @csrf
            <div style="margin-bottom: 1rem;">
                <label for="provenance_note"><strong>{{ __('operations.generated.t_fb3b5a996671c1ee') }}</strong></label>
                <textarea id="provenance_note" name="provenance_note" rows="4" style="width: 100%;" placeholder="{{ __('operations.generated.t_6b3ae229f9ed4081') }}">{{ old('provenance_note') }}</textarea>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label for="tags"><strong>{{ __('operations.generated.t_f292fab612b42ac3') }}</strong></label>
                <input type="text" id="tags" name="tags" style="width: 100%;" value="{{ old('tags') }}" placeholder="{{ __('operations.generated.t_f832d8cfe18f353d') }}">
            </div>

            <button type="submit" class="button">{{ __('operations.generated.t_bf6a8f8b4f3f40a0') }}</button>
            <a href="{{ route('admin.operations.duplicates.index') }}" class="secondary" style="margin-left: 1rem;">Annuleren</a>
        </form>
    </section>
@endsection
