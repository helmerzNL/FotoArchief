@extends('layouts.app')
@section('title', 'Nieuwe herkomstbron — FotoArchief')
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.sources.index') }}">&larr; Terug naar herkomstbronnen</a></div>
    <h1>Nieuwe herkomstbron</h1>

    <form method="post" action="{{ route('catalogue.sources.store') }}">
        @csrf

        <label for="name">Naam van bron / archief *</label>
        <input type="text" id="name" name="name" value="{{ old('name') }}" required autofocus placeholder="bijv. Stadsarchief Breda, Schenking Familie De Vries">

        <div class="grid">
            <div>
                <label for="source_type">Type bron *</label>
                <select id="source_type" name="source_type" required>
                    <option value="archive" {{ old('source_type') === 'archive' ? 'selected' : '' }}>Archief / Openbare instelling</option>
                    <option value="donor" {{ old('source_type') === 'donor' ? 'selected' : '' }}>Schenking / Particuliere schenker</option>
                    <option value="collection" {{ old('source_type') === 'collection' ? 'selected' : '' }}>Deelcollectie / Verzameling</option>
                    <option value="family" {{ old('source_type') === 'family' ? 'selected' : '' }}>Familiearchief</option>
                    <option value="other" {{ old('source_type') === 'other' ? 'selected' : '' }}>Overig</option>
                </select>
            </div>
            <div>
                <label for="reference_code">Referentiecode / Toegangsnummer</label>
                <input type="text" id="reference_code" name="reference_code" value="{{ old('reference_code') }}" placeholder="bijv. TOEG-0482, DOOS-12">
            </div>
        </div>

        <label for="acquisition_date">Datum van verwerving / opname</label>
        <input type="date" id="acquisition_date" name="acquisition_date" value="{{ old('acquisition_date') }}">

        <label for="custody_history">Herkomstgeschiedenis / Bewaargeschiedenis (custody)</label>
        <textarea id="custody_history" name="custody_history" rows="3" placeholder="bijv. Overgedragen in 1984 via notaris, voorheen bewaard op zolder villa">{{ old('custody_history') }}</textarea>

        <label for="description">Beschrijving / Notities</label>
        <textarea id="description" name="description" rows="3">{{ old('description') }}</textarea>

        <div class="actions">
            <button type="submit">Bron opslaan</button>
            <a href="{{ route('catalogue.sources.index') }}" class="button secondary">Annuleren</a>
        </div>
    </form>
</div>
@endsection
