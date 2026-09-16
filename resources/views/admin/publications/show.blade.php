@extends('layouts.app')
@section('title', 'Publicatie - FotoArchief')
@section('content')
    @php($publication = $asset->publication)
    <p class="eyebrow">Publicatie</p>
    <h1>{{ $asset->title ?? $asset->accession_number }}</h1>
    <p class="intro">Status: <strong>{{ $publication?->status ?? 'concept, nog niet aangevraagd' }}</strong>
        @if($publication?->needsReReview())<br><strong>Metadata is gewijzigd sinds publicatie; foto is publiek verborgen tot herbeoordeling.</strong>@endif
    </p>
    @if($publication?->permalink_slug)
        <p>Permalink: <code>/foto/{{ $publication->permalink_slug }}</code></p>
    @endif

    @if(!$publication || in_array($publication->status, ['draft'], true))
        <section class="card">
            <h2>Aanvragen voor review</h2>
            <form method="post" action="{{ route('admin.publications.submit', $asset) }}">
                @csrf
                <label><input type="checkbox" name="privacy_cleared" value="1" required> Privacy gecontroleerd: geen identificeerbare personen zonder toestemming zichtbaar</label>
                <label>Downloadbeleid
                    <select name="download_policy">
                        <option value="preview_only">Voorbeeldweergave downloadbaar</option>
                        <option value="none">Alleen bekijken, geen download</option>
                    </select>
                </label>
                <label>Bronvermelding <input type="text" name="credit_line" maxlength="500"></label>
                <label>Embargo tot (optioneel) <input type="date" name="embargo_until"></label>
                <button type="submit">Aanvragen</button>
            </form>
        </section>
    @endif

    @if($publication?->status === 'in_review' && auth()->user()->hasPermission('assets.publish'))
        <section class="card">
            <h2>Beoordelen</h2>
            <form method="post" action="{{ route('admin.publications.publish', $asset) }}">@csrf<button type="submit">Publiceren</button></form>
            <form method="post" action="{{ route('admin.publications.reject', $asset) }}">
                @csrf
                <label>Reden <textarea name="reject_reason" required maxlength="2000"></textarea></label>
                <button type="submit">Afwijzen</button>
            </form>
        </section>
    @endif

    @if($publication?->status === 'published' && auth()->user()->hasPermission('assets.publish'))
        <section class="card">
            <h2>Intrekken</h2>
            <form method="post" action="{{ route('admin.publications.revoke', $asset) }}">
                @csrf
                <label>Reden <textarea name="revoked_reason" required maxlength="2000"></textarea></label>
                <button type="submit">Direct intrekken</button>
            </form>
        </section>
    @endif

    <h2>Geschiedenis</h2>
    <ul>
    @foreach($events as $event)
        <li>{{ $event->created_at }} &middot; {{ $event->event_type }}</li>
    @endforeach
    </ul>
@endsection
