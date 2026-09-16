@extends('layouts.app')
@section('title', 'Locatie bewerken — '.$location->name)
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.locations.show', $location) }}">&larr; Terug naar overzicht</a></div>
    <h1>Locatie bewerken</h1>

    <form method="post" action="{{ route('catalogue.locations.update', $location) }}">
        @csrf
        @method('PUT')

        <label for="name">Naam van de locatie *</label>
        <input type="text" id="name" name="name" value="{{ old('name', $location->name) }}" required autofocus>

        <div class="grid">
            <div>
                <label for="location_type">Niveau / Type *</label>
                <select id="location_type" name="location_type" required>
                    @foreach(['country', 'province', 'municipality', 'city', 'neighbourhood', 'street', 'building', 'landmark', 'place'] as $type)
                        <option value="{{ $type }}" {{ old('location_type', $location->location_type) === $type ? 'selected' : '' }}>{{ ucfirst($type) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="parent_id">Bovenliggende locatie</label>
                <select id="parent_id" name="parent_id">
                    <option value="">Geen (topniveau locatie)</option>
                    @foreach($availableParents as $l)
                        <option value="{{ $l->id }}" {{ old('parent_id', $location->parent_id) === $l->id ? 'selected' : '' }}>
                            {{ $l->name }} ({{ $l->location_type }})
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <label for="historical_period">Historische periode</label>
        <input type="text" id="historical_period" name="historical_period" value="{{ old('historical_period', $location->historical_period) }}">

        <div class="grid">
            <div>
                <label for="latitude">Breedtegraad (Latitude)</label>
                <input type="text" id="latitude" name="latitude" value="{{ old('latitude', $location->latitude) }}">
            </div>
            <div>
                <label for="longitude">Lengtegraad (Longitude)</label>
                <input type="text" id="longitude" name="longitude" value="{{ old('longitude', $location->longitude) }}">
            </div>
        </div>

        <label for="aliases">Historische aliassen / Oude straatnamen</label>
        <textarea id="aliases" name="aliases" rows="2">{{ old('aliases', $location->aliases->pluck('name')->implode(', ')) }}</textarea>

        <label for="description">Beschrijving / Historische context</label>
        <textarea id="description" name="description" rows="4">{{ old('description', $location->description) }}</textarea>

        <div class="actions">
            <button type="submit">Wijzigingen opslaan</button>
            <a href="{{ route('catalogue.locations.show', $location) }}" class="button secondary">Annuleren</a>
        </div>
    </form>

    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
        <h3>Locatie verwijderen</h3>
        <p style="color: var(--muted); font-size: .9rem;">
            Foto’s worden niet verwijderd, enkel losgekoppeld van deze locatie. Sublocaties worden verplaatst naar het bovenliggende niveau.
        </p>
        <form method="post" action="{{ route('catalogue.locations.destroy', $location) }}" onsubmit="return confirm('Weet je zeker dat je deze locatie wilt verwijderen?');">
            @csrf
            @method('DELETE')
            <button type="submit" style="background: var(--error); border-color: var(--error);">Locatie verwijderen</button>
        </form>
    </div>
</div>
@endsection
