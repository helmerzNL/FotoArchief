@extends('layouts.app')
@section('title', 'Identiteit bewerken — '.$person->display_name)
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.people.show', $person) }}">&larr; Terug naar overzicht</a></div>
    <h1>Identiteit bewerken</h1>

    <form method="post" action="{{ route('catalogue.people.update', $person) }}">
        @csrf
        @method('PUT')

        <label for="entity_type">Type identiteit *</label>
        <select id="entity_type" name="entity_type" required>
            <option value="person" {{ old('entity_type', $person->entity_type) === 'person' ? 'selected' : '' }}>Persoon (individu)</option>
            <option value="organisation" {{ old('entity_type', $person->entity_type) === 'organisation' ? 'selected' : '' }}>Organisatie (vereniging, bedrijf, instelling)</option>
        </select>

        <label for="display_name">Naam / Weergavenaam *</label>
        <input type="text" id="display_name" name="display_name" value="{{ old('display_name', $person->display_name) }}" required autofocus>

        <label for="sort_name">Sorteernaam</label>
        <input type="text" id="sort_name" name="sort_name" value="{{ old('sort_name', $person->sort_name) }}">

        <fieldset style="border: 1px solid var(--border); border-radius: .5rem; padding: 1rem; margin: 1rem 0;">
            <legend>Geboorte / Oprichting</legend>
            <div class="grid">
                <div>
                    <label for="birth_date_precision">Precisie</label>
                    <select id="birth_date_precision" name="birth_date_precision">
                        @foreach(['unknown', 'exact', 'circa', 'year', 'decade', 'range', 'before', 'after'] as $prec)
                            <option value="{{ $prec }}" {{ old('birth_date_precision', $person->birth_date_precision) === $prec ? 'selected' : '' }}>{{ ucfirst($prec) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="birth_date_earliest">Datum (vanaf/exact)</label>
                    <input type="date" id="birth_date_earliest" name="birth_date_earliest" value="{{ old('birth_date_earliest', $person->birth_date_earliest?->format('Y-m-d')) }}">
                </div>
            </div>
            <label for="birth_date_latest">Datum tot (bij bereik)</label>
            <input type="date" id="birth_date_latest" name="birth_date_latest" value="{{ old('birth_date_latest', $person->birth_date_latest?->format('Y-m-d')) }}">
        </fieldset>

        <fieldset style="border: 1px solid var(--border); border-radius: .5rem; padding: 1rem; margin: 1rem 0;">
            <legend>Overlijden / Opheffing</legend>
            <div class="grid">
                <div>
                    <label for="death_date_precision">Precisie</label>
                    <select id="death_date_precision" name="death_date_precision">
                        @foreach(['unknown', 'exact', 'circa', 'year', 'decade', 'range', 'before', 'after'] as $prec)
                            <option value="{{ $prec }}" {{ old('death_date_precision', $person->death_date_precision) === $prec ? 'selected' : '' }}>{{ ucfirst($prec) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="death_date_earliest">Datum (vanaf/exact)</label>
                    <input type="date" id="death_date_earliest" name="death_date_earliest" value="{{ old('death_date_earliest', $person->death_date_earliest?->format('Y-m-d')) }}">
                </div>
            </div>
            <label for="death_date_latest">Datum tot (bij bereik)</label>
            <input type="date" id="death_date_latest" name="death_date_latest" value="{{ old('death_date_latest', $person->death_date_latest?->format('Y-m-d')) }}">
        </fieldset>

        <label for="aliases">Aliassen / Alternatieve namen / Meisjesnamen</label>
        <textarea id="aliases" name="aliases" rows="2">{{ old('aliases', $person->aliases->pluck('name')->implode(', ')) }}</textarea>

        <label for="biographical_note">Biografische / Historische notitie</label>
        <textarea id="biographical_note" name="biographical_note" rows="4">{{ old('biographical_note', $person->biographical_note) }}</textarea>

        <div class="actions">
            <button type="submit">Wijzigingen opslaan</button>
            <a href="{{ route('catalogue.people.show', $person) }}" class="button secondary">Annuleren</a>
        </div>
    </form>

    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
        <h3>Identiteit verwijderen</h3>
        <p style="color: var(--muted); font-size: .9rem;">
            Foto’s worden niet verwijderd, enkel losgekoppeld van deze persoon/organisatie.
        </p>
        <form method="post" action="{{ route('catalogue.people.destroy', $person) }}" onsubmit="return confirm('Weet je zeker dat je deze persoon/organisatie wilt verwijderen?');">
            @csrf
            @method('DELETE')
            <button type="submit" style="background: var(--error); border-color: var(--error);">Verwijderen</button>
        </form>
    </div>
</div>
@endsection
