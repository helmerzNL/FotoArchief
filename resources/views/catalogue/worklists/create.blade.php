@extends('layouts.app')
@section('title', 'Nieuwe Werklijst - FotoArchief')
@section('content')
<p class="eyebrow"><a href="{{ route('catalogue.worklists.index') }}">← Terug naar Werklijsten</a></p>
<h1>Nieuwe werklijst aanmaken</h1>

@if($errors->any())
    <div class="card" style="border-color: #b91c1c; background-color: #fef2f2;">
        <h2>Invoerfouten</h2>
        <ul>
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="post" action="{{ route('catalogue.worklists.store') }}">
    @csrf
    <section class="card">
        <div class="field">
            <label for="title">Titel van de werklijst *</label>
            <input type="text" id="title" name="title" value="{{ old('title') }}" required maxlength="255" placeholder="bijv. Dateren glasnegatieven jaren 20">
        </div>

        <div class="grid">
            <div>
                <label for="worklist_type">Type curatiewachtrij *</label>
                <select id="worklist_type" name="worklist_type" required>
                    <option value="missing_date" {{ old('worklist_type', $defaultType) === 'missing_date' ? 'selected' : '' }}>Ontbrekende datering (missing_date)</option>
                    <option value="missing_rights" {{ old('worklist_type', $defaultType) === 'missing_rights' ? 'selected' : '' }}>Ongeverifieerde rechten (missing_rights)</option>
                    <option value="missing_identification" {{ old('worklist_type', $defaultType) === 'missing_identification' ? 'selected' : '' }}>Ontbrekende personen &amp; locaties (missing_identification)</option>
                    <option value="missing_provenance" {{ old('worklist_type', $defaultType) === 'missing_provenance' ? 'selected' : '' }}>Ontbrekende bron/herkomst (missing_provenance)</option>
                    <option value="custom" {{ old('worklist_type', $defaultType) === 'custom' ? 'selected' : '' }}>Handmatige selectie (custom)</option>
                </select>
            </div>
            <div>
                <label for="limit">Maximaal aantal items importeren</label>
                <input type="number" id="limit" name="limit" value="{{ old('limit', 50) }}" min="1" max="500">
            </div>
        </div>

        <div class="field">
            <label for="assigned_to_user_id">Toewijzen aan medewerker / vrijwilliger</label>
            <select id="assigned_to_user_id" name="assigned_to_user_id">
                <option value="">-- Niet toegewezen --</option>
                @foreach($users as $u)
                    <option value="{{ $u->id }}" {{ old('assigned_to_user_id') == $u->id ? 'selected' : '' }}>{{ $u->name }} ({{ $u->email }})</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="description">Beschrijving / Curatie-instructies</label>
            <textarea id="description" name="description">{{ old('description') }}</textarea>
        </div>

        <div class="actions">
            <button type="submit">Werklijst genereren</button>
            <a href="{{ route('catalogue.worklists.index') }}" class="button secondary">Annuleren</a>
        </div>
    </section>
</form>
@endsection
