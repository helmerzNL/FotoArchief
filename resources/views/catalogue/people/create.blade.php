@extends('layouts.app')
@section('title', 'Nieuwe identiteit toevoegen — FotoArchief')
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.people.index') }}">&larr; Terug naar overzicht</a></div>
    <h1>Nieuwe persoon of organisatie</h1>

    <form method="post" action="{{ route('catalogue.people.store') }}">
        @csrf

        <label for="entity_type">Type identiteit *</label>
        <select id="entity_type" name="entity_type" required>
            <option value="person" {{ old('entity_type') === 'person' ? 'selected' : '' }}>Persoon (individu)</option>
            <option value="organisation" {{ old('entity_type') === 'organisation' ? 'selected' : '' }}>Organisatie (vereniging, bedrijf, instelling)</option>
        </select>

        <label for="display_name">Naam / Weergavenaam *</label>
        <input type="text" id="display_name" name="display_name" value="{{ old('display_name') }}" required autofocus placeholder="bijv. Jan Jansen of Gemeentehuis Best">

        <label for="sort_name">Sorteernaam</label>
        <input type="text" id="sort_name" name="sort_name" value="{{ old('sort_name') }}" placeholder="bijv. Jansen, Jan (automatisch als leeg)">

        <fieldset style="border: 1px solid var(--border); border-radius: .5rem; padding: 1rem; margin: 1rem 0;">
            <legend>Geboorte / Oprichting</legend>
            <div class="grid">
                <div>
                    <label for="birth_date_precision">Precisie</label>
                    <select id="birth_date_precision" name="birth_date_precision">
                        <option value="unknown">Onbekend</option>
                        <option value="exact">Exact</option>
                        <option value="circa">Circa</option>
                        <option value="year">Jaar</option>
                        <option value="decade">Decennium</option>
                        <option value="range">Bereik</option>
                        <option value="before">Vóór</option>
                        <option value="after">Na</option>
                    </select>
                </div>
                <div>
                    <label for="birth_date_earliest">Datum (vanaf/exact)</label>
                    <input type="date" id="birth_date_earliest" name="birth_date_earliest" value="{{ old('birth_date_earliest') }}">
                </div>
            </div>
            <label for="birth_date_latest">Datum tot (bij bereik)</label>
            <input type="date" id="birth_date_latest" name="birth_date_latest" value="{{ old('birth_date_latest') }}">
        </fieldset>

        <fieldset style="border: 1px solid var(--border); border-radius: .5rem; padding: 1rem; margin: 1rem 0;">
            <legend>Overlijden / Opheffing</legend>
            <div class="grid">
                <div>
                    <label for="death_date_precision">Precisie</label>
                    <select id="death_date_precision" name="death_date_precision">
                        <option value="unknown">Onbekend</option>
                        <option value="exact">Exact</option>
                        <option value="circa">Circa</option>
                        <option value="year">Jaar</option>
                        <option value="decade">Decennium</option>
                        <option value="range">Bereik</option>
                        <option value="before">Vóór</option>
                        <option value="after">Na</option>
                    </select>
                </div>
                <div>
                    <label for="death_date_earliest">Datum (vanaf/exact)</label>
                    <input type="date" id="death_date_earliest" name="death_date_earliest" value="{{ old('death_date_earliest') }}">
                </div>
            </div>
            <label for="death_date_latest">Datum tot (bij bereik)</label>
            <input type="date" id="death_date_latest" name="death_date_latest" value="{{ old('death_date_latest') }}">
        </fieldset>

        <label for="aliases">Aliassen / Alternatieve namen / Meisjesnamen</label>
        <textarea id="aliases" name="aliases" rows="2" placeholder="Komma-gescheiden of één per regel">{{ old('aliases') }}</textarea>

        <label for="biographical_note">Biografische / Historische notitie</label>
        <textarea id="biographical_note" name="biographical_note" rows="4">{{ old('biographical_note') }}</textarea>

        <div class="actions">
            <button type="submit">Identiteit opslaan</button>
            <a href="{{ route('catalogue.people.index') }}" class="button secondary">Annuleren</a>
        </div>
    </form>
</div>
@endsection
