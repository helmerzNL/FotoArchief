@extends('layouts.app')
@section('title', 'Catalogusoverzicht — Vistora')
@section('content')
<div class="card">
    <div class="eyebrow">Beheer</div>
    <h1>Catalogus</h1>
    <p class="intro">{{ __('catalogue.generated.t_5ad99c8b8fba625c') }}</p>
</div>

<div class="grid">
    <div class="card">
        <h2>{{ __('catalogue.generated.t_498f6ac003fac8f7') }}</h2>
        <p>{{ __('catalogue.generated.t_1bf5813fd6e01ab7') }}</p>
        <p><strong>{{ $stats['collections_count'] }}</strong> {{ __('catalogue.generated.t_0a49c434403abfa4') }}</p>
        <div class="actions">
            <a href="{{ route('catalogue.collections.index') }}" class="button">{{ __('catalogue.generated.t_c8479293d8666df2') }}</a>
            <a href="{{ route('catalogue.collections.create') }}" class="button secondary">{{ __('catalogue.generated.t_7d137f430760617a') }}</a>
        </div>
    </div>
    <div class="card">
        <h2>{{ __('catalogue.generated.t_57e6c76d611f6dff') }}</h2>
        <p>{{ __('catalogue.generated.t_77bc124b6278e01f') }}</p>
        <p><strong>{{ $stats['people_count'] }}</strong> {{ __('catalogue.generated.t_042ac7a0dce6d506') }}</p>
        <div class="actions">
            <a href="{{ route('catalogue.people.index') }}" class="button secondary">{{ __('catalogue.generated.t_171ccc26611063fb') }}</a>
        </div>
    </div>
    <div class="card">
        <h2>Locaties</h2>
        <p>{{ __('catalogue.generated.t_d3a073290a9976b5') }}</p>
        <p><strong>{{ $stats['locations_count'] }}</strong> {{ __('catalogue.generated.t_1f9c820856992f3a') }}</p>
        <div class="actions">
            <a href="{{ route('catalogue.locations.index') }}" class="button secondary">{{ __('catalogue.generated.t_08c10a479bd6590d') }}</a>
        </div>
    </div>
    <div class="card">
        <h2>{{ __('catalogue.generated.t_d32d56531c92b26c') }}</h2>
        <p>{{ __('catalogue.generated.t_3b91a2625bf0c58e') }}</p>
        <p><strong>{{ $stats['sources_count'] + $stats['contributors_count'] }}</strong> herkomstregistraties.</p>
        <div class="actions">
            <a href="{{ route('catalogue.sources.index') }}" class="button secondary">{{ __('catalogue.generated.t_9ae93ea927e07c99') }}</a>
        </div>
    </div>
    <div class="card">
        <h2>{{ __('catalogue.generated.t_edf0cac4b05289f5') }}</h2>
        <p>{{ __('catalogue.generated.t_f96beddcfc46dd88') }}</p>
        <p><strong>{{ $stats['tags_count'] }}</strong> {{ __('catalogue.generated.t_47f6eac858b00920') }}</p>
        <div class="actions">
            <a href="{{ route('catalogue.tags.index') }}" class="button secondary">{{ __('catalogue.generated.t_2f4e2ccff6c67134') }}</a>
        </div>
    </div>
    <div class="card">
        <h2>{{ __('catalogue.generated.t_001cb953b9ca0b97') }}</h2>
        <p>{{ __('catalogue.generated.t_6a92046b8c376c4f') }}</p>
        <div class="actions">
            <a href="{{ route('catalogue.worklists.index') }}" class="button secondary">{{ __('catalogue.generated.t_c5ce1908436cc902') }}</a>
        </div>
    </div>
</div>
@endsection
