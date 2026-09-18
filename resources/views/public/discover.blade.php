@extends('layouts.app')
@section('title', __('shell.discovery.title'))
@section('content')
    <div class="discovery-hero">
    <p class="eyebrow">{{ __('publication.generated.t_05722e41037c766f') }}</p>
    <h1>@if(request()->routeIs('public.home')){{ __('shell.brand.tagline') }}@else {{ __('publication.generated.t_82e7da97cbd81b7c') }} @endif</h1>
    @if(request()->routeIs('public.home'))<p class="intro">{{ __('publication.generated.t_05e1a0396ccfa924') }}</p>@endif
    </div>
    <div class="search-panel">
    <form class="search-fields" method="get" action="{{ route('public.discover') }}">
        <div>
        <label for="q">{{ __('publication.generated.t_a63bc3b3e256d205') }}</label>
        <input id="q" name="q" type="search" value="{{ request('q') }}" maxlength="200">
        </div>
        <div>
        <label for="tag">{{ __('shell.discovery.keyword') }}</label>
        <select id="tag" name="tag">
            <option value="">{{ __('publication.generated.t_84910209468b482a') }}</option>
            @foreach($tags as $tag)
                <option value="{{ $tag->slug }}" @selected(request('tag') === $tag->slug)>{{ $tag->name }}</option>
            @endforeach
        </select>
        </div>
        <button>{{ __('shell.discovery.filter') }}</button>
        <a href="{{ route('public.discover') }}">{{ __('publication.generated.t_b81cc74c63510f91') }}</a>
    </form>
    <details @if(request()->filled('semantic_q') || $semanticError) open @endif>
    <summary>{{ __('shell.discovery.semantic') }}</summary>
    <form class="semantic-form" method="get" action="{{ route('public.discover') }}">
        <label for="semantic_q">{{ __('ai.public.label') }}</label>
        <input id="semantic_q" name="semantic_q" value="{{ request('semantic_q') }}" maxlength="200" placeholder="{{ __('ai.public.placeholder') }}">
        <p class="hint">{{ __('ai.public.notice') }}</p>
        <label class="check">
            <input type="checkbox" id="semantic_consent" name="semantic_consent" value="1" @checked(request()->boolean('semantic_consent'))>
            {{ __('ai.public.consent') }}
        </label>
        <button class="secondary">{{ __('ai.public.submit') }}</button>
    </form>
    @if($semanticError)
        <p role="alert">{{ __('ai.public.unavailable', ['error' => $semanticError]) }}</p>
    @endif
    </details>
    </div>
    <section class="gallery" aria-label="{{ __('shell.discovery.results') }}">
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
            <p class="empty-state">{{ __('publication.generated.t_4b76db9d96d9f49c') }}</p>
        @endforelse
    </section>
    @if($nextCursor)
        <a class="button secondary" href="{{ route('public.discover', array_filter(['q' => request('q'), 'tag' => request('tag'), 'collection' => request('collection'), 'cursor' => $nextCursor])) }}">{{ __('publication.generated.t_78904cbff656c1f8') }}</a>
    @endif
@endsection
