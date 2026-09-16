@extends('layouts.app')
@section('title', $collection->title.' - FotoArchief')
@section('content')
    <p class="eyebrow">Collectie</p>
    <h1>{{ $collection->title }}</h1>
    @if($collection->description)<p class="intro">{{ $collection->description }}</p>@endif
    <section class="gallery" aria-label="Foto’s in deze collectie">
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
            <p>Deze collectie heeft nog geen publieke foto’s.</p>
        @endforelse
    </section>
    @if($nextCursor)
        <a class="button secondary" href="{{ route('public.collections.show', [$collection, 'cursor' => $nextCursor]) }}">Volgende pagina</a>
    @endif
@endsection
