@extends('layouts.app')
@section('title', 'Nieuwe collectie / album — FotoArchief')
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.collections.index') }}">&larr; Terug naar overzicht</a></div>
    <h1>Nieuwe collectie of album</h1>

    <form method="post" action="{{ route('catalogue.collections.store') }}">
        @csrf

        <label for="title">Titel *</label>
        <input type="text" id="title" name="title" value="{{ old('title') }}" required autofocus>

        <label for="slug">URL-code (slug)</label>
        <input type="text" id="slug" name="slug" value="{{ old('slug') }}" placeholder="Laat leeg voor automatische generatie">

        <label for="collection_type">Type *</label>
        <select id="collection_type" name="collection_type" required>
            <option value="collection" {{ old('collection_type') === 'collection' ? 'selected' : '' }}>Collectie (thematisch)</option>
            <option value="album" {{ old('collection_type') === 'album' ? 'selected' : '' }}>Album (fysiek of digitaal fotoboek)</option>
            <option value="series" {{ old('collection_type') === 'series' ? 'selected' : '' }}>Serie (doorlopende fotoreeks)</option>
            <option value="theme" {{ old('collection_type') === 'theme' ? 'selected' : '' }}>Thema (onderwerpsbundel)</option>
        </select>

        <label for="parent_id">Hoofdcollectie (bovenliggend niveau)</label>
        <select id="parent_id" name="parent_id">
            <option value="">Geen (topniveau collectie)</option>
            @foreach($allCollections as $c)
                <option value="{{ $c->id }}" {{ (old('parent_id') ?? $parentId) === $c->id ? 'selected' : '' }}>
                    {{ $c->title }} ({{ $c->collection_type }})
                </option>
            @endforeach
        </select>

        <label for="position">Volgordenummer</label>
        <input type="number" id="position" name="position" value="{{ old('position') }}" min="0" placeholder="bijv. 1, 2, 3">

        <label for="description">Beschrijving</label>
        <textarea id="description" name="description" rows="4">{{ old('description') }}</textarea>

        <div class="actions">
            <button type="submit">Collectie opslaan</button>
            <a href="{{ route('catalogue.collections.index') }}" class="button secondary">Annuleren</a>
        </div>
    </form>
</div>
@endsection
