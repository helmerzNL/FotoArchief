@extends('layouts.app')
@section('title', 'Tag bewerken: ' . $tag->name . ' - Vistora')
@section('content')
<p class="eyebrow"><a href="{{ route('catalogue.tags.show', $tag) }}">{{ __('catalogue.generated.t_5a66e7b59b5cf49c') }}</a></p>
<h1>{{ __('catalogue.generated.t_9b02a395dbf71608') }} {{ $tag->name }}</h1>

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

<form method="post" action="{{ route('catalogue.tags.update', $tag) }}">
    @csrf
    @method('put')
    <section class="card">
        <h2>{{ __('catalogue.generated.t_55d752a074a33c03') }}</h2>
        <div class="field">
            <label for="name">{{ __('catalogue.generated.t_da63f41964ea50b6') }}</label>
            <input type="text" id="name" name="name" value="{{ old('name', $tag->name) }}" required maxlength="100">
        </div>

        <div class="field">
            <label for="synonyms">{{ __('catalogue.generated.t_420b7e9280efd4ca') }}</label>
            <input type="text" id="synonyms" name="synonyms" value="{{ old('synonyms', $tag->synonyms->pluck('name')->join(', ')) }}" placeholder="{{ __('catalogue.generated.t_d5cc939248e3d03f') }}">
        </div>

        <div class="field">
            <label for="description">{{ __('catalogue.generated.t_2a89c3dcc901eaa6') }}</label>
            <textarea id="description" name="description">{{ old('description', $tag->description) }}</textarea>
        </div>

        <div class="actions">
            <button type="submit">{{ __('catalogue.generated.t_bf79797e95177acb') }}</button>
            <a href="{{ route('catalogue.tags.show', $tag) }}" class="button secondary">Annuleren</a>
        </div>
    </section>
</form>

@if($otherTags->isNotEmpty())
<section class="card">
    <h2>{{ __('catalogue.generated.t_712eef25592a3be1') }}</h2>
    <p>{{ __('catalogue.generated.t_a38c28708211ee03') }}{{ $tag->name }}{{ __('catalogue.generated.t_50c5a7978a945f35') }}</p>
    <form method="post" action="{{ route('catalogue.tags.merge', $tag) }}">
        @csrf
        <div class="field">
            <label for="target_tag_id">{{ __('catalogue.generated.t_16112863330c31be') }}</label>
            <select id="target_tag_id" name="target_tag_id" required>
                <option value="">{{ __('catalogue.generated.t_1c672c57cdb3583b') }}</option>
                @foreach($otherTags as $ot)
                    <option value="{{ $ot->id }}">{{ $ot->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="actions">
            <button type="submit" onclick="return confirm('Weet je zeker dat je deze tag wilt samenvoegen? Deze actie kan niet ongedaan worden gemaakt.')">{{ __('catalogue.generated.t_82043b94958228b2') }}</button>
        </div>
    </form>
</section>
@endif

<section class="card">
    <h2>{{ __('catalogue.generated.t_139062b7ef97669c') }}</h2>
    <form method="post" action="{{ route('catalogue.tags.destroy', $tag) }}">
        @csrf
        @method('delete')
        <button type="submit" class="button secondary" style="color: #b91c1c;" onclick="return confirm('Weet je zeker dat je deze tag wilt verwijderen?')">{{ __('catalogue.generated.t_a7fc42260182c959') }}</button>
    </form>
</section>
@endsection
