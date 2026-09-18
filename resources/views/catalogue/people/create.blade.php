@extends('layouts.app')
@section('title', 'Nieuwe identiteit toevoegen — Vistora')
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.people.index') }}">{{ __('catalogue.generated.t_0929a94eafb121fd') }}</a></div>
    <h1>{{ __('catalogue.generated.t_8c57e9051a25d293') }}</h1>

    <form method="post" action="{{ route('catalogue.people.store') }}">
        @csrf

        <label for="entity_type">{{ __('catalogue.generated.t_e0abae660e77ae63') }}</label>
        <select id="entity_type" name="entity_type" required>
            <option value="person" {{ old('entity_type') === 'person' ? 'selected' : '' }}>{{ __('catalogue.generated.t_2b4949ff8a750efe') }}</option>
            <option value="organisation" {{ old('entity_type') === 'organisation' ? 'selected' : '' }}>{{ __('catalogue.generated.t_cbb36accf149dff6') }}</option>
        </select>

        <label for="display_name">{{ __('catalogue.generated.t_597aa9f880649201') }}</label>
        <input type="text" id="display_name" name="display_name" value="{{ old('display_name') }}" required autofocus placeholder="{{ __('catalogue.generated.t_de997d09fac9ee64') }}">

        <label for="sort_name">Sorteernaam</label>
        <input type="text" id="sort_name" name="sort_name" value="{{ old('sort_name') }}" placeholder="{{ __('catalogue.generated.t_729e2e975d29b8cc') }}">

        <fieldset style="border: 1px solid var(--border); border-radius: .5rem; padding: 1rem; margin: 1rem 0;">
            <legend>{{ __('catalogue.generated.t_0c7ca2d57c75b226') }}</legend>
            <div class="grid">
                <div>
                    <label for="birth_date_precision">Precisie</label>
                    <select id="birth_date_precision" name="birth_date_precision">
                        <option value="unknown">Onbekend</option>
                        <option value="exact">Exact</option>
                        <option value="circa">Circa</option>
                        <option value="year">Jaar</option>
                        <option value="decade">Decennium</option>
                        <option value="range">Bereik</option>
                        <option value="before">{{ __('catalogue.generated.t_b2f2386aab368ea8') }}</option>
                        <option value="after">Na</option>
                    </select>
                </div>
                <div>
                    <label for="birth_date_earliest">{{ __('catalogue.generated.t_422fd6df523bb6c6') }}</label>
                    <input type="date" id="birth_date_earliest" name="birth_date_earliest" value="{{ old('birth_date_earliest') }}">
                </div>
            </div>
            <label for="birth_date_latest">{{ __('catalogue.generated.t_9dec86d78fec9711') }}</label>
            <input type="date" id="birth_date_latest" name="birth_date_latest" value="{{ old('birth_date_latest') }}">
        </fieldset>

        <fieldset style="border: 1px solid var(--border); border-radius: .5rem; padding: 1rem; margin: 1rem 0;">
            <legend>{{ __('catalogue.generated.t_f1b50ea17e4becb3') }}</legend>
            <div class="grid">
                <div>
                    <label for="death_date_precision">Precisie</label>
                    <select id="death_date_precision" name="death_date_precision">
                        <option value="unknown">Onbekend</option>
                        <option value="exact">Exact</option>
                        <option value="circa">Circa</option>
                        <option value="year">Jaar</option>
                        <option value="decade">Decennium</option>
                        <option value="range">Bereik</option>
                        <option value="before">{{ __('catalogue.generated.t_b2f2386aab368ea8') }}</option>
                        <option value="after">Na</option>
                    </select>
                </div>
                <div>
                    <label for="death_date_earliest">{{ __('catalogue.generated.t_422fd6df523bb6c6') }}</label>
                    <input type="date" id="death_date_earliest" name="death_date_earliest" value="{{ old('death_date_earliest') }}">
                </div>
            </div>
            <label for="death_date_latest">{{ __('catalogue.generated.t_9dec86d78fec9711') }}</label>
            <input type="date" id="death_date_latest" name="death_date_latest" value="{{ old('death_date_latest') }}">
        </fieldset>

        <label for="aliases">{{ __('catalogue.generated.t_51cb964923bae16a') }}</label>
        <textarea id="aliases" name="aliases" rows="2" placeholder="{{ __('catalogue.generated.t_0b16bb0f8e2fb712') }}">{{ old('aliases') }}</textarea>

        <label for="biographical_note">{{ __('catalogue.generated.t_930820ff94e0d544') }}</label>
        <textarea id="biographical_note" name="biographical_note" rows="4">{{ old('biographical_note') }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('catalogue.generated.t_73fcefbec92d1544') }}</button>
            <a href="{{ route('catalogue.people.index') }}" class="button secondary">Annuleren</a>
        </div>
    </form>
</div>
@endsection
