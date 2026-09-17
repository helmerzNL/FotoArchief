@extends('layouts.app')
@section('title', 'Nieuwe collectie / album — FotoArchief')
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.collections.index') }}">{{ __('catalogue.generated.t_0929a94eafb121fd') }}</a></div>
    <h1>{{ __('catalogue.generated.t_1850d41daabe2f1c') }}</h1>

    <form method="post" action="{{ route('catalogue.collections.store') }}">
        @csrf

        <label for="title">{{ __('catalogue.generated.t_a1710a47def742fc') }}</label>
        <input type="text" id="title" name="title" value="{{ old('title') }}" required autofocus>

        <label for="slug">{{ __('catalogue.generated.t_acfc6d916ea5787c') }}</label>
        <input type="text" id="slug" name="slug" value="{{ old('slug') }}" placeholder="{{ __('catalogue.generated.t_21832178d0e7a078') }}">

        <label for="collection_type">{{ __('catalogue.generated.t_9a8e753ce3ebf633') }}</label>
        <select id="collection_type" name="collection_type" required>
            <option value="collection" {{ old('collection_type') === 'collection' ? 'selected' : '' }}>{{ __('catalogue.generated.t_11d82ce4e99151c7') }}</option>
            <option value="album" {{ old('collection_type') === 'album' ? 'selected' : '' }}>{{ __('catalogue.generated.t_a0e2c599b9d7e046') }}</option>
            <option value="series" {{ old('collection_type') === 'series' ? 'selected' : '' }}>{{ __('catalogue.generated.t_998d549e627fc242') }}</option>
            <option value="theme" {{ old('collection_type') === 'theme' ? 'selected' : '' }}>{{ __('catalogue.generated.t_3a1c6d53c17b0729') }}</option>
        </select>

        <label for="parent_id">{{ __('catalogue.generated.t_fb61b61384b04275') }}</label>
        <select id="parent_id" name="parent_id">
            <option value="">{{ __('catalogue.generated.t_24a48046fdceba43') }}</option>
            @foreach($allCollections as $c)
                <option value="{{ $c->id }}" {{ (old('parent_id') ?? $parentId) === $c->id ? 'selected' : '' }}>
                    {{ $c->title }} ({{ $c->collection_type }})
                </option>
            @endforeach
        </select>

        <label for="position">Volgordenummer</label>
        <input type="number" id="position" name="position" value="{{ old('position') }}" min="0" placeholder="{{ __('catalogue.generated.t_4384e8411b026b9b') }}">

        <label for="description">Beschrijving</label>
        <textarea id="description" name="description" rows="4">{{ old('description') }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('catalogue.generated.t_841ec9e5ad4cbaf6') }}</button>
            <a href="{{ route('catalogue.collections.index') }}" class="button secondary">Annuleren</a>
        </div>
    </form>
</div>
@endsection
