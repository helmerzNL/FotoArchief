@extends('layouts.app')
@section('title', 'Suggestie - FotoArchief')
@section('content')
    <p class="eyebrow">Suggestie</p>
    <h1>{{ $suggestion->asset?->title ?? $suggestion->asset?->accession_number }}</h1>
    <dl class="meta">
        <dt>Type</dt><dd>{{ $suggestion->suggestion_type }}</dd>
        <dt>Bericht</dt><dd>{{ $suggestion->message }}</dd>
        <dt>Naam (optioneel)</dt><dd>{{ $suggestion->submitter_name ?: 'anoniem' }}</dd>
        <dt>E-mail (optioneel)</dt><dd>{{ $suggestion->submitter_email ?: 'onbekend' }}</dd>
        <dt>Status</dt><dd>{{ $suggestion->status }}</dd>
        @if($suggestion->moderator)
            <dt>Beoordeeld door</dt><dd>{{ $suggestion->moderator->name }} op {{ $suggestion->moderated_at }}</dd>
        @endif
    </dl>

    @if($suggestion->status === 'pending' && auth()->user()->hasPermission('assets.update'))
        <section class="card">
            <h2>Beoordelen</h2>
            <p><em>Een goedkeuring past de metadata van de foto niet automatisch aan. Werk de foto zelf bij via de gewone beheeromgeving als de suggestie klopt.</em></p>
            <form method="post" action="{{ route('admin.suggestions.accept', $suggestion) }}">
                @csrf
                <label>Notitie (optioneel) <textarea name="moderator_note" maxlength="2000"></textarea></label>
                <button type="submit">Accepteren</button>
            </form>
            <form method="post" action="{{ route('admin.suggestions.reject', $suggestion) }}">
                @csrf
                <label>Notitie (optioneel) <textarea name="moderator_note" maxlength="2000"></textarea></label>
                <button type="submit">Afwijzen</button>
            </form>
        </section>
    @endif

    @if($suggestion->asset)
        <p><a href="{{ route('admin.publications.show', $suggestion->asset) }}">Naar publicatiebeheer van deze foto</a></p>
    @endif
@endsection
