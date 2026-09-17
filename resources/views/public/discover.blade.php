@extends('layouts.app')
@section('title', 'Ontdek de collectie - FotoArchief')
@section('content')
    <p class="eyebrow">{{ __('publication.generated.t_05722e41037c766f') }}</p>
    <h1>@if(request()->routeIs('public.home'))FotoArchief @else {{ __('publication.generated.t_82e7da97cbd81b7c') }} @endif</h1>
    @if(request()->routeIs('public.home'))<p class="intro">{{ __('publication.generated.t_05e1a0396ccfa924') }}</p>@endif
    <form class="actions" method="get" action="{{ route('public.discover') }}">
        <label for="q">{{ __('publication.generated.t_a63bc3b3e256d205') }}</label>
        <input id="q" name="q" value="{{ request('q') }}" maxlength="200">
        <label for="tag">Trefwoord</label>
        <select id="tag" name="tag">
            <option value="">{{ __('publication.generated.t_84910209468b482a') }}</option>
            @foreach($tags as $tag)
                <option value="{{ $tag->slug }}" @selected(request('tag') === $tag->slug)>{{ $tag->name }}</option>
            @endforeach
        </select>
        <button class="secondary">Filteren</button>
        <a href="{{ route('public.discover') }}">{{ __('publication.generated.t_b81cc74c63510f91') }}</a>
    </form>
    <form class="actions" method="get" action="{{ route('public.discover') }}">
        <label for="semantic_q">{{ __('ai.public.label') }}</label>
        <input id="semantic_q" name="semantic_q" value="{{ request('semantic_q') }}" maxlength="200" placeholder="{{ __('ai.public.placeholder') }}">
        <p class="hint">{{ __('ai.public.notice') }}</p>
        <label>
            <input type="checkbox" id="semantic_consent" name="semantic_consent" value="1" @checked(request()->boolean('semantic_consent'))>
            {{ __('ai.public.consent') }}
        </label>
        <button class="secondary">{{ __('ai.public.submit') }}</button>
    </form>
    @if($semanticError)
        <p role="alert">{{ __('ai.public.unavailable', ['error' => $semanticError]) }}</p>
    @endif
    <section class="gallery" aria-label="Zoekresultaten">
        @forelse($publications as $publication)
            @php($asset = $publication->asset)
            @php($file = $asset->files->first())
            <article class="gallery-item">
                <a href="{{ route('public.photo', $publication) }}">
                    @if($file)
                        <img class="thumbnail-large" loading="lazy" src="{{ route('public.photo.media', [$publication, 'preview300']) }}" alt="">
                    @endif
                    <span>{{ $asset->title ?: $asset->accession_number }}</span>
                </a>
            </article>
        @empty
            <p>{{ __('publication.generated.t_4b76db9d96d9f49c') }}</p>
        @endforelse
    </section>
    @if($nextCursor)
        <a class="button secondary" href="{{ route('public.discover', array_filter(['q' => request('q'), 'tag' => request('tag'), 'collection' => request('collection'), 'cursor' => $nextCursor])) }}">{{ __('publication.generated.t_78904cbff656c1f8') }}</a>
    @endif
@endsection
