@extends('layouts.app')
@section('title', 'Collectie bewerken — '.$collection->title)
@section('content')
<div class="card narrow">
    <div class="eyebrow"><a href="{{ route('catalogue.collections.show', $collection) }}">{{ __('catalogue.generated.t_54534e467023d402') }}</a></div>
    <h1>{{ __('catalogue.generated.t_d824760b6bb3a102') }}</h1>

    <form method="post" action="{{ route('catalogue.collections.update', $collection) }}">
        @csrf
        @method('PUT')

        <label for="title">{{ __('catalogue.generated.t_a1710a47def742fc') }}</label>
        <input type="text" id="title" name="title" value="{{ old('title', $collection->title) }}" required autofocus>

        <label for="slug">{{ __('catalogue.generated.t_b83e64504333afaf') }}</label>
        <input type="text" id="slug" name="slug" value="{{ old('slug', $collection->slug) }}" required>

        <label for="collection_type">{{ __('catalogue.generated.t_9a8e753ce3ebf633') }}</label>
        <select id="collection_type" name="collection_type" required>
            <option value="collection" {{ old('collection_type', $collection->collection_type) === 'collection' ? 'selected' : '' }}>{{ __('catalogue.generated.t_11d82ce4e99151c7') }}</option>
            <option value="album" {{ old('collection_type', $collection->collection_type) === 'album' ? 'selected' : '' }}>{{ __('catalogue.generated.t_a0e2c599b9d7e046') }}</option>
            <option value="series" {{ old('collection_type', $collection->collection_type) === 'series' ? 'selected' : '' }}>{{ __('catalogue.generated.t_998d549e627fc242') }}</option>
            <option value="theme" {{ old('collection_type', $collection->collection_type) === 'theme' ? 'selected' : '' }}>{{ __('catalogue.generated.t_3a1c6d53c17b0729') }}</option>
        </select>

        <label for="parent_id">{{ __('catalogue.generated.t_fb61b61384b04275') }}</label>
        <select id="parent_id" name="parent_id">
            <option value="">{{ __('catalogue.generated.t_24a48046fdceba43') }}</option>
            @foreach($availableParents as $c)
                <option value="{{ $c->id }}" {{ old('parent_id', $collection->parent_id) === $c->id ? 'selected' : '' }}>
                    {{ $c->title }} ({{ $c->collection_type }})
                </option>
            @endforeach
        </select>

        <label for="position">Volgordenummer</label>
        <input type="number" id="position" name="position" value="{{ old('position', $collection->position) }}" min="0">

        <label for="description">Beschrijving</label>
        <textarea id="description" name="description" rows="4">{{ old('description', $collection->description) }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('catalogue.generated.t_bf79797e95177acb') }}</button>
            <a href="{{ route('catalogue.collections.show', $collection) }}" class="button secondary">Annuleren</a>
        </div>
    </form>

    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
        <h3>{{ __('catalogue.generated.t_d9d52bd9942efa89') }}</h3>
        <p style="color: var(--muted); font-size: .9rem;">
            {{ __('catalogue.generated.t_3575394187091010') }}
        </p>
        <form method="post" action="{{ route('catalogue.collections.destroy', $collection) }}" onsubmit="return confirm('Weet je zeker dat je deze collectie wilt verwijderen?');">
            @csrf
            @method('DELETE')
            <button type="submit" style="background: var(--error); border-color: var(--error);">{{ __('catalogue.generated.t_d9d52bd9942efa89') }}</button>
        </form>
    </div>
</div>
@endsection
