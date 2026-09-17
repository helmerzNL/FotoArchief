@extends('layouts.app')
@section('title', 'Schenker bewerken — '.$contributor->name)
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.contributors.show', $contributor) }}">{{ __('catalogue.generated.t_b9f6974f440a8095') }}</a></div>
    <h1>{{ __('catalogue.generated.t_00fce35df1837da5') }}</h1>

    <form method="post" action="{{ route('catalogue.contributors.update', $contributor) }}">
        @csrf
        @method('PUT')

        <label for="name">{{ __('catalogue.generated.t_dd759821a09b8cfc') }}</label>
        <input type="text" id="name" name="name" value="{{ old('name', $contributor->name) }}" required autofocus>

        <div class="grid">
            <div>
                <label for="contributor_type">{{ __('catalogue.generated.t_c04154b82e094c0e') }}</label>
                <select id="contributor_type" name="contributor_type" required>
                    @foreach(['individual', 'organisation', 'donor', 'photographer', 'collector'] as $type)
                        <option value="{{ $type }}" {{ old('contributor_type', $contributor->contributor_type) === $type ? 'selected' : '' }}>{{ ucfirst($type) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="email">E-mailadres</label>
                <input type="email" id="email" name="email" value="{{ old('email', $contributor->email) }}">
            </div>
        </div>

        <label for="contact_details">{{ __('catalogue.generated.t_121b415ab6086654') }}</label>
        <textarea id="contact_details" name="contact_details" rows="2">{{ old('contact_details', $contributor->contact_details) }}</textarea>

        <label for="note">{{ __('catalogue.generated.t_f311e0e01c15af26') }}</label>
        <textarea id="note" name="note" rows="3">{{ old('note', $contributor->note) }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('catalogue.generated.t_bf79797e95177acb') }}</button>
            <a href="{{ route('catalogue.contributors.show', $contributor) }}" class="button secondary">Annuleren</a>
        </div>
    </form>

    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
        <h3>{{ __('catalogue.generated.t_74ea3469439b0185') }}</h3>
        <p style="color: var(--muted); font-size: .9rem;">
            {{ __('catalogue.generated.t_d477aae2a036120d') }}
        </p>
        <form method="post" action="{{ route('catalogue.contributors.destroy', $contributor) }}" onsubmit="return confirm('Weet je zeker dat je deze bijdrager wilt verwijderen?');">
            @csrf
            @method('DELETE')
            <button type="submit" style="background: var(--error); border-color: var(--error);">{{ __('catalogue.generated.t_74ea3469439b0185') }}</button>
        </form>
    </div>
</div>
@endsection
