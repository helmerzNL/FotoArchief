@extends('layouts.app')
@section('title', 'Nieuwe locatie — FotoArchief')
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.locations.index') }}">&larr; Terug naar overzicht</a></div>
    <h1>Nieuwe locatie toevoegen</h1>

    <form method="post" action="{{ route('catalogue.locations.store') }}">
        @csrf

        <label for="name">Naam van de locatie *</label>
        <input type="text" id="name" name="name" value="{{ old('name') }}" required autofocus placeholder="bijv. Markt, Kerkstraat of Eindhoven">

        <div class="grid">
            <div>
                <label for="location_type">Niveau / Type *</label>
                <select id="location_type" name="location_type" required>
                    <option value="country" {{ old('location_type') === 'country' ? 'selected' : '' }}>Land</option>
                    <option value="province" {{ old('location_type') === 'province' ? 'selected' : '' }}>Provincie / Regio</option>
                    <option value="municipality" {{ old('location_type') === 'municipality' ? 'selected' : '' }}>Gemeente</option>
                    <option value="city" {{ old('location_type') === 'city' ? 'selected' : '' }}>Stad / Dorp / Plaats</option>
                    <option value="neighbourhood" {{ old('location_type') === 'neighbourhood' ? 'selected' : '' }}>Wijk / Buurt</option>
                    <option value="street" {{ old('location_type', 'street') === 'street' ? 'selected' : '' }}>Straat</option>
                    <option value="building" {{ old('location_type') === 'building' ? 'selected' : '' }}>Gebouw / Pand</option>
                    <option value="landmark" {{ old('location_type') === 'landmark' ? 'selected' : '' }}>Monument / Landmark</option>
                    <option value="place" {{ old('location_type') === 'place' ? 'selected' : '' }}>Overige locatie</option>
                </select>
            </div>
            <div>
                <label for="parent_id">Bovenliggende locatie</label>
                <select id="parent_id" name="parent_id">
                    <option value="">Geen (topniveau locatie)</option>
                    @foreach($allLocations as $l)
                        <option value="{{ $l->id }}" {{ (old('parent_id') ?? $parentId) === $l->id ? 'selected' : '' }}>
                            {{ $l->name }} ({{ $l->location_type }})
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <label for="historical_period">Historische periode</label>
        <input type="text" id="historical_period" name="historical_period" value="{{ old('historical_period') }}" placeholder="bijv. 1850-1940, Middeleeuwen, vóór grenswijziging 1920">

        <div class="grid">
            <div>
                <label for="latitude">Breedtegraad (Latitude)</label>
                <input type="text" id="latitude" name="latitude" value="{{ old('latitude') }}" placeholder="bijv. 51.441642">
            </div>
            <div>
                <label for="longitude">Lengtegraad (Longitude)</label>
                <input type="text" id="longitude" name="longitude" value="{{ old('longitude') }}" placeholder="bijv. 5.469722">
            </div>
        </div>

        <label for="aliases">Historische aliassen / Oude straatnamen</label>
        <textarea id="aliases" name="aliases" rows="2" placeholder="Komma-gescheiden of één per regel">{{ old('aliases') }}</textarea>

        <label for="description">Beschrijving / Historische context</label>
        <textarea id="description" name="description" rows="4">{{ old('description') }}</textarea>

        <div class="actions">
            <button type="submit">Locatie opslaan</button>
            <a href="{{ route('catalogue.locations.index') }}" class="button secondary">Annuleren</a>
        </div>
    </form>
</div>
@endsection
