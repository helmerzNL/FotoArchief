@extends('layouts.app')
@section('title', 'Collectie bewerken — '.$collection->title)
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.collections.show', $collection) }}">&larr; Terug naar collectie</a></div>
    <h1>Collectie bewerken</h1>

    <form method="post" action="{{ route('catalogue.collections.update', $collection) }}">
        @csrf
        @method('PUT')

        <label for="title">Titel *</label>
        <input type="text" id="title" name="title" value="{{ old('title', $collection->title) }}" required autofocus>

        <label for="slug">URL-code (slug) *</label>
        <input type="text" id="slug" name="slug" value="{{ old('slug', $collection->slug) }}" required>

        <label for="collection_type">Type *</label>
        <select id="collection_type" name="collection_type" required>
            <option value="collection" {{ old('collection_type', $collection->collection_type) === 'collection' ? 'selected' : '' }}>Collectie (thematisch)</option>
            <option value="album" {{ old('collection_type', $collection->collection_type) === 'album' ? 'selected' : '' }}>Album (fysiek of digitaal fotoboek)</option>
            <option value="series" {{ old('collection_type', $collection->collection_type) === 'series' ? 'selected' : '' }}>Serie (doorlopende fotoreeks)</option>
            <option value="theme" {{ old('collection_type', $collection->collection_type) === 'theme' ? 'selected' : '' }}>Thema (onderwerpsbundel)</option>
        </select>

        <label for="parent_id">Hoofdcollectie (bovenliggend niveau)</label>
        <select id="parent_id" name="parent_id">
            <option value="">Geen (topniveau collectie)</option>
            @foreach($availableParents as $c)
                <option value="{{ $c->id }}" {{ old('parent_id', $collection->parent_id) === $c->id ? 'selected' : '' }}>
                    {{ $c->title }} ({{ $c->collection_type }})
                </option>
            @endforeach
        </select>

        <label for="position">Volgordenummer</label>
        <input type="number" id="position" name="position" value="{{ old('position', $collection->position) }}" min="0">

        <label for="description">Beschrijving</label>
        <textarea id="description" name="description" rows="4">{{ old('description', $collection->description) }}</textarea>

        <div class="actions">
            <button type="submit">Wijzigingen opslaan</button>
            <a href="{{ route('catalogue.collections.show', $collection) }}" class="button secondary">Annuleren</a>
        </div>
    </form>

    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
        <h3>Collectie verwijderen</h3>
        <p style="color: var(--muted); font-size: .9rem;">
            Foto’s worden niet verwijderd, enkel losgekoppeld uit deze collectie. Subcollecties worden verplaatst naar de bovenliggende collectie.
        </p>
        <form method="post" action="{{ route('catalogue.collections.destroy', $collection) }}" onsubmit="return confirm('Weet je zeker dat je deze collectie wilt verwijderen?');">
            @csrf
            @method('DELETE')
            <button type="submit" style="background: var(--error); border-color: var(--error);">Collectie verwijderen</button>
        </form>
    </div>
</div>
@endsection
