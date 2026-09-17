@extends('layouts.app')
@section('title', 'Nieuwe Tag - FotoArchief')
@section('content')
<p class="eyebrow"><a href="{{ route('catalogue.tags.index') }}">{{ __('catalogue.generated.t_cdedd3d79f8fd443') }}</a></p>
<h1>{{ __('catalogue.generated.t_8bc07be057f85631') }}</h1>

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

<form method="post" action="{{ route('catalogue.tags.store') }}">
    @csrf
    <section class="card">
        <div class="field">
            <label for="name">{{ __('catalogue.generated.t_da63f41964ea50b6') }}</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="100">
        </div>

        <div class="field">
            <label for="synonyms">{{ __('catalogue.generated.t_420b7e9280efd4ca') }}</label>
            <input type="text" id="synonyms" name="synonyms" value="{{ old('synonyms') }}" placeholder="{{ __('catalogue.generated.t_d5cc939248e3d03f') }}">
        </div>

        <div class="field">
            <label for="description">{{ __('catalogue.generated.t_2a89c3dcc901eaa6') }}</label>
            <textarea id="description" name="description">{{ old('description') }}</textarea>
        </div>

        <div class="actions">
            <button type="submit">{{ __('catalogue.generated.t_75fd3359f77982cd') }}</button>
            <a href="{{ route('catalogue.tags.index') }}" class="button secondary">Annuleren</a>
        </div>
    </section>
</form>
@endsection
