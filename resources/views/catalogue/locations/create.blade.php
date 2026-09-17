@extends('layouts.app')
@section('title', 'Nieuwe locatie — FotoArchief')
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.locations.index') }}">{{ __('catalogue.generated.t_0929a94eafb121fd') }}</a></div>
    <h1>{{ __('catalogue.generated.t_91cc0bcc63b4765a') }}</h1>

    <form method="post" action="{{ route('catalogue.locations.store') }}">
        @csrf

        <label for="name">{{ __('catalogue.generated.t_0b2d0bea79e3a0fb') }}</label>
        <input type="text" id="name" name="name" value="{{ old('name') }}" required autofocus placeholder="{{ __('catalogue.generated.t_745736c4550853a2') }}">

        <div class="grid">
            <div>
                <label for="location_type">{{ __('catalogue.generated.t_5e49a2cb7f739fab') }}</label>
                <select id="location_type" name="location_type" required>
                    <option value="country" {{ old('location_type') === 'country' ? 'selected' : '' }}>Land</option>
                    <option value="province" {{ old('location_type') === 'province' ? 'selected' : '' }}>{{ __('catalogue.generated.t_87a55f3bbca46fc4') }}</option>
                    <option value="municipality" {{ old('location_type') === 'municipality' ? 'selected' : '' }}>Gemeente</option>
                    <option value="city" {{ old('location_type') === 'city' ? 'selected' : '' }}>{{ __('catalogue.generated.t_118fb64b6b7f6bd7') }}</option>
                    <option value="neighbourhood" {{ old('location_type') === 'neighbourhood' ? 'selected' : '' }}>{{ __('catalogue.generated.t_8762b0fa486bc6bf') }}</option>
                    <option value="street" {{ old('location_type', 'street') === 'street' ? 'selected' : '' }}>Straat</option>
                    <option value="building" {{ old('location_type') === 'building' ? 'selected' : '' }}>{{ __('catalogue.generated.t_ae79b07d14836154') }}</option>
                    <option value="landmark" {{ old('location_type') === 'landmark' ? 'selected' : '' }}>{{ __('catalogue.generated.t_93e1ada8175c84df') }}</option>
                    <option value="place" {{ old('location_type') === 'place' ? 'selected' : '' }}>{{ __('catalogue.generated.t_5e341e14643f152b') }}</option>
                </select>
            </div>
            <div>
                <label for="parent_id">{{ __('catalogue.generated.t_44349746d69d55a8') }}</label>
                <select id="parent_id" name="parent_id">
                    <option value="">{{ __('catalogue.generated.t_ca4389e9dd784118') }}</option>
                    @foreach($allLocations as $l)
                        <option value="{{ $l->id }}" {{ (old('parent_id') ?? $parentId) === $l->id ? 'selected' : '' }}>
                            {{ $l->name }} ({{ $l->location_type }})
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <label for="historical_period">{{ __('catalogue.generated.t_a72cbc4f1ca72fda') }}</label>
        <input type="text" id="historical_period" name="historical_period" value="{{ old('historical_period') }}" placeholder="{{ __('catalogue.generated.t_bb470d6df8bb9c6b') }}">

        <div class="grid">
            <div>
                <label for="latitude">{{ __('catalogue.generated.t_e95ec3d23f62cf36') }}</label>
                <input type="text" id="latitude" name="latitude" value="{{ old('latitude') }}" placeholder="{{ __('catalogue.generated.t_e1687dc238522881') }}">
            </div>
            <div>
                <label for="longitude">{{ __('catalogue.generated.t_5ddcb35df8803adf') }}</label>
                <input type="text" id="longitude" name="longitude" value="{{ old('longitude') }}" placeholder="{{ __('catalogue.generated.t_721c022721a54620') }}">
            </div>
        </div>

        <label for="aliases">{{ __('catalogue.generated.t_ca5f9bb43462da65') }}</label>
        <textarea id="aliases" name="aliases" rows="2" placeholder="{{ __('catalogue.generated.t_0b16bb0f8e2fb712') }}">{{ old('aliases') }}</textarea>

        <label for="description">{{ __('catalogue.generated.t_fba70de094f2af8b') }}</label>
        <textarea id="description" name="description" rows="4">{{ old('description') }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('catalogue.generated.t_da8d84e8906039f6') }}</button>
            <a href="{{ route('catalogue.locations.index') }}" class="button secondary">Annuleren</a>
        </div>
    </form>
</div>
@endsection
