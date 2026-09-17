@extends('layouts.app')
@section('title', 'Nieuwe herkomstbron — FotoArchief')
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.sources.index') }}">{{ __('catalogue.generated.t_255f8d2f0cecea7a') }}</a></div>
    <h1>{{ __('catalogue.generated.t_9f8c939e49a72c95') }}</h1>

    <form method="post" action="{{ route('catalogue.sources.store') }}">
        @csrf

        <label for="name">{{ __('catalogue.generated.t_057b030fddf2cebd') }}</label>
        <input type="text" id="name" name="name" value="{{ old('name') }}" required autofocus placeholder="{{ __('catalogue.generated.t_89353b81dbd36d9b') }}">

        <div class="grid">
            <div>
                <label for="source_type">{{ __('catalogue.generated.t_f42320fb941c2981') }}</label>
                <select id="source_type" name="source_type" required>
                    <option value="archive" {{ old('source_type') === 'archive' ? 'selected' : '' }}>{{ __('catalogue.generated.t_97efefc8b40e4c78') }}</option>
                    <option value="donor" {{ old('source_type') === 'donor' ? 'selected' : '' }}>{{ __('catalogue.generated.t_8aa7973254ff9243') }}</option>
                    <option value="collection" {{ old('source_type') === 'collection' ? 'selected' : '' }}>{{ __('catalogue.generated.t_d4cd8eceec53f4c1') }}</option>
                    <option value="family" {{ old('source_type') === 'family' ? 'selected' : '' }}>Familiearchief</option>
                    <option value="other" {{ old('source_type') === 'other' ? 'selected' : '' }}>Overig</option>
                </select>
            </div>
            <div>
                <label for="reference_code">{{ __('catalogue.generated.t_6836963586d078d0') }}</label>
                <input type="text" id="reference_code" name="reference_code" value="{{ old('reference_code') }}" placeholder="{{ __('catalogue.generated.t_1d49ec7b795e27e1') }}">
            </div>
        </div>

        <label for="acquisition_date">{{ __('catalogue.generated.t_1675e2ea48b11822') }}</label>
        <input type="date" id="acquisition_date" name="acquisition_date" value="{{ old('acquisition_date') }}">

        <label for="custody_history">{{ __('catalogue.generated.t_4cd972b96c721402') }}</label>
        <textarea id="custody_history" name="custody_history" rows="3" placeholder="{{ __('catalogue.generated.t_09829fc9145ce65b') }}">{{ old('custody_history') }}</textarea>

        <label for="description">{{ __('catalogue.generated.t_2b33f1edf6e17a27') }}</label>
        <textarea id="description" name="description" rows="3">{{ old('description') }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('catalogue.generated.t_24641f58f94113e1') }}</button>
            <a href="{{ route('catalogue.sources.index') }}" class="button secondary">Annuleren</a>
        </div>
    </form>
</div>
@endsection
