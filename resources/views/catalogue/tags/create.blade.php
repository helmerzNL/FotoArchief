@extends('layouts.app')
@section('title', 'Nieuwe Tag - FotoArchief')
@section('content')
<p class="eyebrow"><a href="{{ route('catalogue.tags.index') }}">← Terug naar Tags</a></p>
<h1>Nieuwe tag toevoegen</h1>

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

<form method="post" action="{{ route('catalogue.tags.store') }}">
    @csrf
    <section class="card">
        <div class="field">
            <label for="name">Tagnaam / Hoofdterm *</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="100">
        </div>

        <div class="field">
            <label for="synonyms">Synoniemen &amp; varianten (komma-gescheiden)</label>
            <input type="text" id="synonyms" name="synonyms" value="{{ old('synonyms') }}" placeholder="bijv. godshuis, bedeplaats, kapel">
        </div>

        <div class="field">
            <label for="description">Beschrijving / Scope-notitie</label>
            <textarea id="description" name="description">{{ old('description') }}</textarea>
        </div>

        <div class="actions">
            <button type="submit">Tag opslaan</button>
            <a href="{{ route('catalogue.tags.index') }}" class="button secondary">Annuleren</a>
        </div>
    </section>
</form>
@endsection
