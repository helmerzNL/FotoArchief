@extends('layouts.app')
@section('title', 'Achtergrondtaken - FotoArchief Operaties')
@section('content')
    @include('operations._nav')
    <p class="eyebrow">Operaties &middot; Achtergrondtaken</p>
    <h1>Achtergrondtaken</h1>
    <p class="intro">Alle zware archiefbewerkingen draaien op de ingest-wachtrij met een zichtbare status, foutmelding en herpoging.</p>

    @include('operations.runs._panel', ['runs' => $runs])

    <div style="margin-top: 1rem;">
        {{ $runs->links() }}
    </div>
@endsection
