@extends('layouts.app')
@section('title', 'Ontdek de collectie - FotoArchief')
@section('content')
    <p class="eyebrow">Publieke collectie</p>
    <h1>Ontdek foto’s</h1>
    <form class="actions" method="get" action="{{ route('public.discover') }}">
        <label for="q">Zoeken op titel of beschrijving</label>
        <input id="q" name="q" value="{{ request('q') }}" maxlength="200">
        <label for="tag">Trefwoord</label>
        <select id="tag" name="tag">
            <option value="">Alle trefwoorden</option>
            @foreach($tags as $tag)
                <option value="{{ $tag->slug }}" @selected(request('tag') === $tag->slug)>{{ $tag->name }}</option>
            @endforeach
        </select>
        <button class="secondary">Filteren</button>
        <a href="{{ route('public.discover') }}">Filters wissen</a>
    </form>
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
            <p>Geen foto’s gevonden.</p>
        @endforelse
    </section>
    @if($nextCursor)
        <a class="button secondary" href="{{ route('public.discover', array_filter(['q' => request('q'), 'tag' => request('tag'), 'collection' => request('collection'), 'cursor' => $nextCursor])) }}">Volgende pagina</a>
    @endif
@endsection
