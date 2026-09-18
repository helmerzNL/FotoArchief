@extends('layouts.app')
@section('title', 'Suggestie - Vistora')
@section('content')
    <p class="eyebrow">Suggestie</p>
    <h1>{{ $suggestion->asset?->title ?? $suggestion->asset?->accession_number }}</h1>
    <dl class="meta">
        <dt>Type</dt><dd>{{ $suggestion->suggestion_type }}</dd>
        <dt>Bericht</dt><dd>{{ $suggestion->message }}</dd>
        <dt>{{ __('publication.generated.t_74a69ced12f4da9e') }}</dt><dd>{{ $suggestion->submitter_name ?: 'anoniem' }}</dd>
        <dt>{{ __('publication.generated.t_06b6f83d892fbc8a') }}</dt><dd>{{ $suggestion->submitter_email ?: 'onbekend' }}</dd>
        <dt>Status</dt><dd>{{ $suggestion->status }}</dd>
        @if($suggestion->moderator)
            <dt>{{ __('publication.generated.t_2d63214466448a69') }}</dt><dd>{{ $suggestion->moderator->name }} op {{ $suggestion->moderated_at }}</dd>
        @endif
    </dl>

    @if($suggestion->status === 'pending' && auth()->user()->hasPermission('assets.update'))
        <section class="card">
            <h2>Beoordelen</h2>
            <p><em>{{ __('publication.generated.t_b18da0eeb8f23182') }}</em></p>
            <form method="post" action="{{ route('admin.suggestions.accept', $suggestion) }}">
                @csrf
                <label>{{ __('publication.generated.t_d111dfbcca7960ef') }} <textarea name="moderator_note" maxlength="2000"></textarea></label>
                <button type="submit">Accepteren</button>
            </form>
            <form method="post" action="{{ route('admin.suggestions.reject', $suggestion) }}">
                @csrf
                <label>{{ __('publication.generated.t_d111dfbcca7960ef') }} <textarea name="moderator_note" maxlength="2000"></textarea></label>
                <button type="submit">Afwijzen</button>
            </form>
        </section>
    @endif

    @if($suggestion->asset)
        <p><a href="{{ route('admin.publications.show', $suggestion->asset) }}">{{ __('publication.generated.t_eaafbddac91ecaed') }}</a></p>
    @endif
@endsection
