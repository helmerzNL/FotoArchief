@extends('layouts.app')
@section('title', 'Herkomstbron bewerken — '.$source->name)
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.sources.show', $source) }}">{{ __('catalogue.generated.t_445b9df2bf10888d') }}</a></div>
    <h1>{{ __('catalogue.generated.t_d63404a65027c8f9') }}</h1>

    <form method="post" action="{{ route('catalogue.sources.update', $source) }}">
        @csrf
        @method('PUT')

        <label for="name">{{ __('catalogue.generated.t_057b030fddf2cebd') }}</label>
        <input type="text" id="name" name="name" value="{{ old('name', $source->name) }}" required autofocus>

        <div class="grid">
            <div>
                <label for="source_type">{{ __('catalogue.generated.t_f42320fb941c2981') }}</label>
                <select id="source_type" name="source_type" required>
                    @foreach(['archive', 'donor', 'institution', 'collection', 'family', 'other'] as $type)
                        <option value="{{ $type }}" {{ old('source_type', $source->source_type) === $type ? 'selected' : '' }}>{{ ucfirst($type) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="reference_code">{{ __('catalogue.generated.t_6836963586d078d0') }}</label>
                <input type="text" id="reference_code" name="reference_code" value="{{ old('reference_code', $source->reference_code) }}">
            </div>
        </div>

        <label for="acquisition_date">{{ __('catalogue.generated.t_1675e2ea48b11822') }}</label>
        <input type="date" id="acquisition_date" name="acquisition_date" value="{{ old('acquisition_date', $source->acquisition_date?->format('Y-m-d')) }}">

        <label for="custody_history">{{ __('catalogue.generated.t_4cd972b96c721402') }}</label>
        <textarea id="custody_history" name="custody_history" rows="3">{{ old('custody_history', $source->custody_history) }}</textarea>

        <label for="description">{{ __('catalogue.generated.t_2b33f1edf6e17a27') }}</label>
        <textarea id="description" name="description" rows="3">{{ old('description', $source->description) }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('catalogue.generated.t_bf79797e95177acb') }}</button>
            <a href="{{ route('catalogue.sources.show', $source) }}" class="button secondary">Annuleren</a>
        </div>
    </form>

    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
        <h3>{{ __('catalogue.generated.t_00ade5481178f9c6') }}</h3>
        <p style="color: var(--muted); font-size: .9rem;">
            {{ __('catalogue.generated.t_bc5e11bcc516339b') }}
        </p>
        <form method="post" action="{{ route('catalogue.sources.destroy', $source) }}" onsubmit="return confirm('Weet je zeker dat je deze herkomstbron wilt verwijderen?');">
            @csrf
            @method('DELETE')
            <button type="submit" style="background: var(--error); border-color: var(--error);">{{ __('catalogue.generated.t_00ade5481178f9c6') }}</button>
        </form>
    </div>
</div>
@endsection
