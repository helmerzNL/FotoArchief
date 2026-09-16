@extends('layouts.app')
@section('title', 'Nieuwe schenker / bijdrager — FotoArchief')
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.contributors.index') }}">&larr; Terug naar overzicht</a></div>
    <h1>Nieuwe schenker / bijdrager</h1>

    <form method="post" action="{{ route('catalogue.contributors.store') }}">
        @csrf

        <label for="name">Naam *</label>
        <input type="text" id="name" name="name" value="{{ old('name') }}" required autofocus placeholder="bijv. H. van den Berg of Fotoclub De Sluiter">

        <div class="grid">
            <div>
                <label for="contributor_type">Type bijdrager *</label>
                <select id="contributor_type" name="contributor_type" required>
                    <option value="donor" {{ old('contributor_type', 'donor') === 'donor' ? 'selected' : '' }}>Schenker / Donateur</option>
                    <option value="photographer" {{ old('contributor_type') === 'photographer' ? 'selected' : '' }}>Fotograaf</option>
                    <option value="collector" {{ old('contributor_type') === 'collector' ? 'selected' : '' }}>Verzamelaar</option>
                    <option value="individual" {{ old('contributor_type') === 'individual' ? 'selected' : '' }}>Individu</option>
                    <option value="organisation" {{ old('contributor_type') === 'organisation' ? 'selected' : '' }}>Organisatie</option>
                </select>
            </div>
            <div>
                <label for="email">E-mailadres</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" placeholder="naam@domein.nl">
            </div>
        </div>

        <label for="contact_details">Contactgegevens / Adresnotitie (intern)</label>
        <textarea id="contact_details" name="contact_details" rows="2" placeholder="Niet publiek zichtbaar">{{ old('contact_details') }}</textarea>

        <label for="note">Notitie / Afspraken</label>
        <textarea id="note" name="note" rows="3">{{ old('note') }}</textarea>

        <div class="actions">
            <button type="submit">Opslaan</button>
            <a href="{{ route('catalogue.contributors.index') }}" class="button secondary">Annuleren</a>
        </div>
    </form>
</div>
@endsection
