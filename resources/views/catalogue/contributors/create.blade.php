@extends('layouts.app')
@section('title', 'Nieuwe schenker / bijdrager — FotoArchief')
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.contributors.index') }}">{{ __('catalogue.generated.t_0929a94eafb121fd') }}</a></div>
    <h1>{{ __('catalogue.generated.t_281964215755896a') }}</h1>

    <form method="post" action="{{ route('catalogue.contributors.store') }}">
        @csrf

        <label for="name">{{ __('catalogue.generated.t_dd759821a09b8cfc') }}</label>
        <input type="text" id="name" name="name" value="{{ old('name') }}" required autofocus placeholder="{{ __('catalogue.generated.t_dfe41f740a52ee6b') }}">

        <div class="grid">
            <div>
                <label for="contributor_type">{{ __('catalogue.generated.t_c04154b82e094c0e') }}</label>
                <select id="contributor_type" name="contributor_type" required>
                    <option value="donor" {{ old('contributor_type', 'donor') === 'donor' ? 'selected' : '' }}>{{ __('catalogue.generated.t_6b7c5cff25c942d4') }}</option>
                    <option value="photographer" {{ old('contributor_type') === 'photographer' ? 'selected' : '' }}>Fotograaf</option>
                    <option value="collector" {{ old('contributor_type') === 'collector' ? 'selected' : '' }}>Verzamelaar</option>
                    <option value="individual" {{ old('contributor_type') === 'individual' ? 'selected' : '' }}>Individu</option>
                    <option value="organisation" {{ old('contributor_type') === 'organisation' ? 'selected' : '' }}>Organisatie</option>
                </select>
            </div>
            <div>
                <label for="email">E-mailadres</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" placeholder="naam@domein.nl">
            </div>
        </div>

        <label for="contact_details">{{ __('catalogue.generated.t_121b415ab6086654') }}</label>
        <textarea id="contact_details" name="contact_details" rows="2" placeholder="{{ __('catalogue.generated.t_5997b0982c47f974') }}">{{ old('contact_details') }}</textarea>

        <label for="note">{{ __('catalogue.generated.t_f311e0e01c15af26') }}</label>
        <textarea id="note" name="note" rows="3">{{ old('note') }}</textarea>

        <div class="actions">
            <button type="submit">Opslaan</button>
            <a href="{{ route('catalogue.contributors.index') }}" class="button secondary">Annuleren</a>
        </div>
    </form>
</div>
@endsection
