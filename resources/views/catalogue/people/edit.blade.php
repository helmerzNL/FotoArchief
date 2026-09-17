@extends('layouts.app')
@section('title', 'Identiteit bewerken — '.$person->display_name)
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.people.show', $person) }}">{{ __('catalogue.generated.t_0929a94eafb121fd') }}</a></div>
    <h1>{{ __('catalogue.generated.t_9c6e93322ca13d51') }}</h1>

    <form method="post" action="{{ route('catalogue.people.update', $person) }}">
        @csrf
        @method('PUT')

        <label for="entity_type">{{ __('catalogue.generated.t_e0abae660e77ae63') }}</label>
        <select id="entity_type" name="entity_type" required>
            <option value="person" {{ old('entity_type', $person->entity_type) === 'person' ? 'selected' : '' }}>{{ __('catalogue.generated.t_2b4949ff8a750efe') }}</option>
            <option value="organisation" {{ old('entity_type', $person->entity_type) === 'organisation' ? 'selected' : '' }}>{{ __('catalogue.generated.t_cbb36accf149dff6') }}</option>
        </select>

        <label for="display_name">{{ __('catalogue.generated.t_597aa9f880649201') }}</label>
        <input type="text" id="display_name" name="display_name" value="{{ old('display_name', $person->display_name) }}" required autofocus>

        <label for="sort_name">Sorteernaam</label>
        <input type="text" id="sort_name" name="sort_name" value="{{ old('sort_name', $person->sort_name) }}">

        <fieldset style="border: 1px solid var(--border); border-radius: .5rem; padding: 1rem; margin: 1rem 0;">
            <legend>{{ __('catalogue.generated.t_0c7ca2d57c75b226') }}</legend>
            <div class="grid">
                <div>
                    <label for="birth_date_precision">Precisie</label>
                    <select id="birth_date_precision" name="birth_date_precision">
                        @foreach(['unknown', 'exact', 'circa', 'year', 'decade', 'range', 'before', 'after'] as $prec)
                            <option value="{{ $prec }}" {{ old('birth_date_precision', $person->birth_date_precision) === $prec ? 'selected' : '' }}>{{ ucfirst($prec) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="birth_date_earliest">{{ __('catalogue.generated.t_422fd6df523bb6c6') }}</label>
                    <input type="date" id="birth_date_earliest" name="birth_date_earliest" value="{{ old('birth_date_earliest', $person->birth_date_earliest?->format('Y-m-d')) }}">
                </div>
            </div>
            <label for="birth_date_latest">{{ __('catalogue.generated.t_9dec86d78fec9711') }}</label>
            <input type="date" id="birth_date_latest" name="birth_date_latest" value="{{ old('birth_date_latest', $person->birth_date_latest?->format('Y-m-d')) }}">
        </fieldset>

        <fieldset style="border: 1px solid var(--border); border-radius: .5rem; padding: 1rem; margin: 1rem 0;">
            <legend>{{ __('catalogue.generated.t_f1b50ea17e4becb3') }}</legend>
            <div class="grid">
                <div>
                    <label for="death_date_precision">Precisie</label>
                    <select id="death_date_precision" name="death_date_precision">
                        @foreach(['unknown', 'exact', 'circa', 'year', 'decade', 'range', 'before', 'after'] as $prec)
                            <option value="{{ $prec }}" {{ old('death_date_precision', $person->death_date_precision) === $prec ? 'selected' : '' }}>{{ ucfirst($prec) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="death_date_earliest">{{ __('catalogue.generated.t_422fd6df523bb6c6') }}</label>
                    <input type="date" id="death_date_earliest" name="death_date_earliest" value="{{ old('death_date_earliest', $person->death_date_earliest?->format('Y-m-d')) }}">
                </div>
            </div>
            <label for="death_date_latest">{{ __('catalogue.generated.t_9dec86d78fec9711') }}</label>
            <input type="date" id="death_date_latest" name="death_date_latest" value="{{ old('death_date_latest', $person->death_date_latest?->format('Y-m-d')) }}">
        </fieldset>

        <label for="aliases">{{ __('catalogue.generated.t_51cb964923bae16a') }}</label>
        <textarea id="aliases" name="aliases" rows="2">{{ old('aliases', $person->aliases->pluck('name')->implode(', ')) }}</textarea>

        <label for="biographical_note">{{ __('catalogue.generated.t_930820ff94e0d544') }}</label>
        <textarea id="biographical_note" name="biographical_note" rows="4">{{ old('biographical_note', $person->biographical_note) }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('catalogue.generated.t_bf79797e95177acb') }}</button>
            <a href="{{ route('catalogue.people.show', $person) }}" class="button secondary">Annuleren</a>
        </div>
    </form>

    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
        <h3>{{ __('catalogue.generated.t_8797c6f6b506d7d6') }}</h3>
        <p style="color: var(--muted); font-size: .9rem;">
            {{ __('catalogue.generated.t_cdbc46881e4cb7f3') }}
        </p>
        <form method="post" action="{{ route('catalogue.people.destroy', $person) }}" onsubmit="return confirm('Weet je zeker dat je deze persoon/organisatie wilt verwijderen?');">
            @csrf
            @method('DELETE')
            <button type="submit" style="background: var(--error); border-color: var(--error);">Verwijderen</button>
        </form>
    </div>
</div>
@endsection
