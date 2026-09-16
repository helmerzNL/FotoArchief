@extends('layouts.app')
@section('title', 'Schenker bewerken — '.$contributor->name)
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.contributors.show', $contributor) }}">&larr; Terug naar bijdrager</a></div>
    <h1>Schenker / bijdrager bewerken</h1>

    <form method="post" action="{{ route('catalogue.contributors.update', $contributor) }}">
        @csrf
        @method('PUT')

        <label for="name">Naam *</label>
        <input type="text" id="name" name="name" value="{{ old('name', $contributor->name) }}" required autofocus>

        <div class="grid">
            <div>
                <label for="contributor_type">Type bijdrager *</label>
                <select id="contributor_type" name="contributor_type" required>
                    @foreach(['individual', 'organisation', 'donor', 'photographer', 'collector'] as $type)
                        <option value="{{ $type }}" {{ old('contributor_type', $contributor->contributor_type) === $type ? 'selected' : '' }}>{{ ucfirst($type) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="email">E-mailadres</label>
                <input type="email" id="email" name="email" value="{{ old('email', $contributor->email) }}">
            </div>
        </div>

        <label for="contact_details">Contactgegevens / Adresnotitie (intern)</label>
        <textarea id="contact_details" name="contact_details" rows="2">{{ old('contact_details', $contributor->contact_details) }}</textarea>

        <label for="note">Notitie / Afspraken</label>
        <textarea id="note" name="note" rows="3">{{ old('note', $contributor->note) }}</textarea>

        <div class="actions">
            <button type="submit">Wijzigingen opslaan</button>
            <a href="{{ route('catalogue.contributors.show', $contributor) }}" class="button secondary">Annuleren</a>
        </div>
    </form>

    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
        <h3>Bijdrager verwijderen</h3>
        <p style="color: var(--muted); font-size: .9rem;">
            Foto’s worden niet verwijderd, enkel losgekoppeld van deze schenker/bijdrager.
        </p>
        <form method="post" action="{{ route('catalogue.contributors.destroy', $contributor) }}" onsubmit="return confirm('Weet je zeker dat je deze bijdrager wilt verwijderen?');">
            @csrf
            @method('DELETE')
            <button type="submit" style="background: var(--error); border-color: var(--error);">Bijdrager verwijderen</button>
        </form>
    </div>
</div>
@endsection
