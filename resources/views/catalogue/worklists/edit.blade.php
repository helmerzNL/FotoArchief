@extends('layouts.app')
@section('title', 'Werklijst bewerken: ' . $worklist->title . ' - FotoArchief')
@section('content')
<p class="eyebrow"><a href="{{ route('catalogue.worklists.show', $worklist) }}">← Terug naar werklijst</a></p>
<h1>Werklijst bewerken: {{ $worklist->title }}</h1>

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

<form method="post" action="{{ route('catalogue.worklists.update', $worklist) }}">
    @csrf
    @method('put')
    <section class="card">
        <div class="field">
            <label for="title">Titel *</label>
            <input type="text" id="title" name="title" value="{{ old('title', $worklist->title) }}" required maxlength="255">
        </div>

        <div class="grid">
            <div>
                <label for="status">Status *</label>
                <select id="status" name="status" required>
                    <option value="active" {{ old('status', $worklist->status) === 'active' ? 'selected' : '' }}>Actief (active)</option>
                    <option value="in_progress" {{ old('status', $worklist->status) === 'in_progress' ? 'selected' : '' }}>In behandeling (in_progress)</option>
                    <option value="completed" {{ old('status', $worklist->status) === 'completed' ? 'selected' : '' }}>Afgerond (completed)</option>
                    <option value="archived" {{ old('status', $worklist->status) === 'archived' ? 'selected' : '' }}>Gearchiveerd (archived)</option>
                </select>
            </div>
            <div>
                <label for="assigned_to_user_id">Toegewezen aan</label>
                <select id="assigned_to_user_id" name="assigned_to_user_id">
                    <option value="">-- Niet toegewezen --</option>
                    @foreach($users as $u)
                        <option value="{{ $u->id }}" {{ old('assigned_to_user_id', $worklist->assigned_to_user_id) == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="field">
            <label for="description">Beschrijving / Notities</label>
            <textarea id="description" name="description">{{ old('description', $worklist->description) }}</textarea>
        </div>

        <div class="actions">
            <button type="submit">Wijzigingen opslaan</button>
            <a href="{{ route('catalogue.worklists.show', $worklist) }}" class="button secondary">Annuleren</a>
        </div>
    </section>
</form>

<section class="card">
    <h2>Werklijst verwijderen</h2>
    <form method="post" action="{{ route('catalogue.worklists.destroy', $worklist) }}">
        @csrf
        @method('delete')
        <button type="submit" class="button secondary" style="color: #b91c1c;" onclick="return confirm('Weet je zeker dat je deze werklijst wilt verwijderen?')">Werklijst verwijderen</button>
    </form>
</section>
@endsection
