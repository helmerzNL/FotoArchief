@extends('layouts.app')
@section('title', 'Tag bewerken: ' . $tag->name . ' - FotoArchief')
@section('content')
<p class="eyebrow"><a href="{{ route('catalogue.tags.show', $tag) }}">← Terug naar tag</a></p>
<h1>Tag bewerken: {{ $tag->name }}</h1>

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

<form method="post" action="{{ route('catalogue.tags.update', $tag) }}">
    @csrf
    @method('put')
    <section class="card">
        <h2>Gegevens wijzigen</h2>
        <div class="field">
            <label for="name">Tagnaam / Hoofdterm *</label>
            <input type="text" id="name" name="name" value="{{ old('name', $tag->name) }}" required maxlength="100">
        </div>

        <div class="field">
            <label for="synonyms">Synoniemen &amp; varianten (komma-gescheiden)</label>
            <input type="text" id="synonyms" name="synonyms" value="{{ old('synonyms', $tag->synonyms->pluck('name')->join(', ')) }}" placeholder="bijv. godshuis, bedeplaats, kapel">
        </div>

        <div class="field">
            <label for="description">Beschrijving / Scope-notitie</label>
            <textarea id="description" name="description">{{ old('description', $tag->description) }}</textarea>
        </div>

        <div class="actions">
            <button type="submit">Wijzigingen opslaan</button>
            <a href="{{ route('catalogue.tags.show', $tag) }}" class="button secondary">Annuleren</a>
        </div>
    </section>
</form>

@if($otherTags->isNotEmpty())
<section class="card">
    <h2>Tag samenvoegen (Merge)</h2>
    <p>Voeg deze tag samen in een andere tag. Alle gekoppelde foto’s worden behouden onder de doeltag, en de huidige tagnaam ({{ $tag->name }}) wordt automatisch als synoniem toegevoegd.</p>
    <form method="post" action="{{ route('catalogue.tags.merge', $tag) }}">
        @csrf
        <div class="field">
            <label for="target_tag_id">Samenvoegen in doeltag:</label>
            <select id="target_tag_id" name="target_tag_id" required>
                <option value="">-- Kies een doeltag --</option>
                @foreach($otherTags as $ot)
                    <option value="{{ $ot->id }}">{{ $ot->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="actions">
            <button type="submit" onclick="return confirm('Weet je zeker dat je deze tag wilt samenvoegen? Deze actie kan niet ongedaan worden gemaakt.')">Tag samenvoegen</button>
        </div>
    </form>
</section>
@endif

<section class="card">
    <h2>Tag verwijderen</h2>
    <form method="post" action="{{ route('catalogue.tags.destroy', $tag) }}">
        @csrf
        @method('delete')
        <button type="submit" class="button secondary" style="color: #b91c1c;" onclick="return confirm('Weet je zeker dat je deze tag wilt verwijderen?')">Tag definitief verwijderen</button>
    </form>
</section>
@endsection
