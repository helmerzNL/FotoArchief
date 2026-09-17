@extends('layouts.app')
@section('title', 'Locatie bewerken — '.$location->name)
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.locations.show', $location) }}">{{ __('catalogue.generated.t_0929a94eafb121fd') }}</a></div>
    <h1>{{ __('catalogue.generated.t_29d2b5e79bf1b2bb') }}</h1>

    <form method="post" action="{{ route('catalogue.locations.update', $location) }}">
        @csrf
        @method('PUT')

        <label for="name">{{ __('catalogue.generated.t_0b2d0bea79e3a0fb') }}</label>
        <input type="text" id="name" name="name" value="{{ old('name', $location->name) }}" required autofocus>

        <div class="grid">
            <div>
                <label for="location_type">{{ __('catalogue.generated.t_5e49a2cb7f739fab') }}</label>
                <select id="location_type" name="location_type" required>
                    @foreach(['country', 'province', 'municipality', 'city', 'neighbourhood', 'street', 'building', 'landmark', 'place'] as $type)
                        <option value="{{ $type }}" {{ old('location_type', $location->location_type) === $type ? 'selected' : '' }}>{{ ucfirst($type) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="parent_id">{{ __('catalogue.generated.t_44349746d69d55a8') }}</label>
                <select id="parent_id" name="parent_id">
                    <option value="">{{ __('catalogue.generated.t_ca4389e9dd784118') }}</option>
                    @foreach($availableParents as $l)
                        <option value="{{ $l->id }}" {{ old('parent_id', $location->parent_id) === $l->id ? 'selected' : '' }}>
                            {{ $l->name }} ({{ $l->location_type }})
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <label for="historical_period">{{ __('catalogue.generated.t_a72cbc4f1ca72fda') }}</label>
        <input type="text" id="historical_period" name="historical_period" value="{{ old('historical_period', $location->historical_period) }}">

        <div class="grid">
            <div>
                <label for="latitude">{{ __('catalogue.generated.t_e95ec3d23f62cf36') }}</label>
                <input type="text" id="latitude" name="latitude" value="{{ old('latitude', $location->latitude) }}">
            </div>
            <div>
                <label for="longitude">{{ __('catalogue.generated.t_5ddcb35df8803adf') }}</label>
                <input type="text" id="longitude" name="longitude" value="{{ old('longitude', $location->longitude) }}">
            </div>
        </div>

        <label for="aliases">{{ __('catalogue.generated.t_ca5f9bb43462da65') }}</label>
        <textarea id="aliases" name="aliases" rows="2">{{ old('aliases', $location->aliases->pluck('name')->implode(', ')) }}</textarea>

        <label for="description">{{ __('catalogue.generated.t_fba70de094f2af8b') }}</label>
        <textarea id="description" name="description" rows="4">{{ old('description', $location->description) }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('catalogue.generated.t_bf79797e95177acb') }}</button>
            <a href="{{ route('catalogue.locations.show', $location) }}" class="button secondary">Annuleren</a>
        </div>
    </form>

    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
        <h3>{{ __('catalogue.generated.t_53a64169bd00c113') }}</h3>
        <p style="color: var(--muted); font-size: .9rem;">
            {{ __('catalogue.generated.t_2550e42457485b11') }}
        </p>
        <form method="post" action="{{ route('catalogue.locations.destroy', $location) }}" onsubmit="return confirm('Weet je zeker dat je deze locatie wilt verwijderen?');">
            @csrf
            @method('DELETE')
            <button type="submit" style="background: var(--error); border-color: var(--error);">{{ __('catalogue.generated.t_53a64169bd00c113') }}</button>
        </form>
    </div>
</div>
@endsection
