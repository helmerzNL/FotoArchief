@extends('layouts.app')
@section('title', 'Herkomstbron bewerken — '.$source->name)
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.sources.show', $source) }}">&larr; Terug naar bron</a></div>
    <h1>Herkomstbron bewerken</h1>

    <form method="post" action="{{ route('catalogue.sources.update', $source) }}">
        @csrf
        @method('PUT')

        <label for="name">Naam van bron / archief *</label>
        <input type="text" id="name" name="name" value="{{ old('name', $source->name) }}" required autofocus>

        <div class="grid">
            <div>
                <label for="source_type">Type bron *</label>
                <select id="source_type" name="source_type" required>
                    @foreach(['archive', 'donor', 'institution', 'collection', 'family', 'other'] as $type)
                        <option value="{{ $type }}" {{ old('source_type', $source->source_type) === $type ? 'selected' : '' }}>{{ ucfirst($type) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="reference_code">Referentiecode / Toegangsnummer</label>
                <input type="text" id="reference_code" name="reference_code" value="{{ old('reference_code', $source->reference_code) }}">
            </div>
        </div>

        <label for="acquisition_date">Datum van verwerving / opname</label>
        <input type="date" id="acquisition_date" name="acquisition_date" value="{{ old('acquisition_date', $source->acquisition_date?->format('Y-m-d')) }}">

        <label for="custody_history">Herkomstgeschiedenis / Bewaargeschiedenis (custody)</label>
        <textarea id="custody_history" name="custody_history" rows="3">{{ old('custody_history', $source->custody_history) }}</textarea>

        <label for="description">Beschrijving / Notities</label>
        <textarea id="description" name="description" rows="3">{{ old('description', $source->description) }}</textarea>

        <div class="actions">
            <button type="submit">Wijzigingen opslaan</button>
            <a href="{{ route('catalogue.sources.show', $source) }}" class="button secondary">Annuleren</a>
        </div>
    </form>

    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
        <h3>Herkomstbron verwijderen</h3>
        <p style="color: var(--muted); font-size: .9rem;">
            Foto’s worden niet verwijderd, enkel losgekoppeld van deze herkomstbron.
        </p>
        <form method="post" action="{{ route('catalogue.sources.destroy', $source) }}" onsubmit="return confirm('Weet je zeker dat je deze herkomstbron wilt verwijderen?');">
            @csrf
            @method('DELETE')
            <button type="submit" style="background: var(--error); border-color: var(--error);">Herkomstbron verwijderen</button>
        </form>
    </div>
</div>
@endsection
