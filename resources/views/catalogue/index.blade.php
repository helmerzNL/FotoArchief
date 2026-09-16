@extends('layouts.app')
@section('title', 'Catalogusoverzicht — FotoArchief')
@section('content')
<div class="card">
    <div class="eyebrow">Beheer</div>
    <h1>Catalogus</h1>
    <p class="intro">Beheer collecties, albums, personen, locaties, herkomstbronnen, tags en curatiewerklijsten voor het archief.</p>
</div>

<div class="grid">
    <div class="card">
        <h2>Collecties &amp; Albums</h2>
        <p>Organiseer foto’s in thematische verzamelingen, fysieke albums en hiërarchische series.</p>
        <p><strong>{{ $stats['collections_count'] }}</strong> collecties geregistreerd.</p>
        <div class="actions">
            <a href="{{ route('catalogue.collections.index') }}" class="button">Collecties bekijken</a>
            <a href="{{ route('catalogue.collections.create') }}" class="button secondary">+ Nieuwe collectie</a>
        </div>
    </div>
    <div class="card">
        <h2>Personen &amp; Organisaties</h2>
        <p>Herkenbare identiteiten, biografische gegevens, historische aliassen en rollen bij foto’s.</p>
        <p><strong>{{ $stats['people_count'] }}</strong> personen en organisaties.</p>
        <div class="actions">
            <a href="{{ route('catalogue.people.index') }}" class="button secondary">Personen beheren</a>
        </div>
    </div>
    <div class="card">
        <h2>Locaties</h2>
        <p>Hiërarchische geografische structuren, historische plaats- en straatnamen.</p>
        <p><strong>{{ $stats['locations_count'] }}</strong> locaties geregistreerd.</p>
        <div class="actions">
            <a href="{{ route('catalogue.locations.index') }}" class="button secondary">Locaties beheren</a>
        </div>
    </div>
    <div class="card">
        <h2>Bronnen &amp; Herkomst</h2>
        <p>Schenkers, archieven, instellingen en provenance-informatie.</p>
        <p><strong>{{ $stats['sources_count'] + $stats['contributors_count'] }}</strong> herkomstregistraties.</p>
        <div class="actions">
            <a href="{{ route('catalogue.sources.index') }}" class="button secondary">Bronnen beheren</a>
        </div>
    </div>
    <div class="card">
        <h2>Tags &amp; Trefwoorden</h2>
        <p>Gecontroleerde trefwoordenlijst, synoniemen en samenvoegingen.</p>
        <p><strong>{{ $stats['tags_count'] }}</strong> trefwoorden actief.</p>
        <div class="actions">
            <a href="{{ route('catalogue.tags.index') }}" class="button secondary">Tags beheren</a>
        </div>
    </div>
    <div class="card">
        <h2>Werklijsten &amp; Curatie</h2>
        <p>Beheer curatiewerklijsten voor ontbrekende datums, rechten, herkomst en identificatie.</p>
        <div class="actions">
            <a href="{{ route('catalogue.worklists.index') }}" class="button secondary">Werklijsten bekijken</a>
        </div>
    </div>
</div>
@endsection
