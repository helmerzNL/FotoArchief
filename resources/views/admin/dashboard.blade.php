@extends('layouts.app')
@section('title', 'Beheer - FotoArchief')
@section('content')
    <p class="eyebrow">Installatie gereed</p>
    <h1>Welkom, {{ auth()->user()->name }}</h1>
    <p class="intro">Je beheeromgeving staat klaar. Database, opslag en het eerste account zijn geconfigureerd.</p>
    <div class="grid">
        <section class="card"><h2>Database</h2><p>PostgreSQL &middot; {{ config('database.connections.pgsql.database') }}</p></section>
        <section class="card"><h2>Opslag</h2><p>{{ config('filesystems.default') === 'local' ? 'Lokale private opslag' : 'Private S3-compatible opslag' }}</p></section>
        <section class="card"><h2>Installatie</h2><p>Wizard vergrendeld. De instellingen blijven bewaard bij een herstart.</p></section>
        <section class="card"><h2>Eerste foto's</h2><p>Upload private afbeeldingen, volg de verwerking en beschrijf ze voordat er later een publicatieworkflow komt.</p><a class="button" href="{{ route('admin.assets.index') }}">Naar foto's</a></section>
    </div>
    <form method="post" action="/logout">@csrf<button class="secondary" type="submit">Uitloggen</button></form>
@endsection
