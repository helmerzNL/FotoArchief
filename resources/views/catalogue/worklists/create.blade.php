@extends('layouts.app')
@section('title', 'Nieuwe Werklijst - Vistora')
@section('content')
<p class="eyebrow"><a href="{{ route('catalogue.worklists.index') }}">{{ __('catalogue.generated.t_355431f4dd49690f') }}</a></p>
<h1>{{ __('catalogue.generated.t_9e7e4e6fbf701d18') }}</h1>

@if($errors->any())
    <div class="card" style="border-color: #b91c1c; background-color: #fef2f2;">
        <h2>Invoerfouten</h2>
        <ul>
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="post" action="{{ route('catalogue.worklists.store') }}">
    @csrf
    <section class="card">
        <div class="field">
            <label for="title">{{ __('catalogue.generated.t_4ecee590ca878a89') }}</label>
            <input type="text" id="title" name="title" value="{{ old('title') }}" required maxlength="255" placeholder="{{ __('catalogue.generated.t_a25b5e6e008562b9') }}">
        </div>

        <div class="grid">
            <div>
                <label for="worklist_type">{{ __('catalogue.generated.t_9a713fb1fcc1850a') }}</label>
                <select id="worklist_type" name="worklist_type" required>
                    <option value="missing_date" {{ old('worklist_type', $defaultType) === 'missing_date' ? 'selected' : '' }}>{{ __('catalogue.generated.t_e6f626927f42e20d') }}</option>
                    <option value="missing_rights" {{ old('worklist_type', $defaultType) === 'missing_rights' ? 'selected' : '' }}>{{ __('catalogue.generated.t_e9522659edccbd42') }}</option>
                    <option value="missing_identification" {{ old('worklist_type', $defaultType) === 'missing_identification' ? 'selected' : '' }}>{{ __('catalogue.generated.t_7fd14f3e086ca9b7') }}</option>
                    <option value="missing_provenance" {{ old('worklist_type', $defaultType) === 'missing_provenance' ? 'selected' : '' }}>{{ __('catalogue.generated.t_140e748a4f2d35dc') }}</option>
                    <option value="custom" {{ old('worklist_type', $defaultType) === 'custom' ? 'selected' : '' }}>{{ __('catalogue.generated.t_3051682987ce36fb') }}</option>
                </select>
            </div>
            <div>
                <label for="limit">{{ __('catalogue.generated.t_4860e0f79e1118f0') }}</label>
                <input type="number" id="limit" name="limit" value="{{ old('limit', 50) }}" min="1" max="500">
            </div>
        </div>

        <div class="field">
            <label for="assigned_to_user_id">{{ __('catalogue.generated.t_0f6eae4ac386f08b') }}</label>
            <select id="assigned_to_user_id" name="assigned_to_user_id">
                <option value="">{{ __('catalogue.generated.t_86274edafc6b08c0') }}</option>
                @foreach($users as $u)
                    <option value="{{ $u->id }}" {{ old('assigned_to_user_id') == $u->id ? 'selected' : '' }}>{{ $u->name }} ({{ $u->email }})</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="description">{{ __('catalogue.generated.t_e6783dccde3c0cb8') }}</label>
            <textarea id="description" name="description">{{ old('description') }}</textarea>
        </div>

        <div class="actions">
            <button type="submit">{{ __('catalogue.generated.t_874a7b46ddfb0151') }}</button>
            <a href="{{ route('catalogue.worklists.index') }}" class="button secondary">Annuleren</a>
        </div>
    </section>
</form>
@endsection
