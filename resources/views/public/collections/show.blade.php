@extends('layouts.app')
@section('title', $collection->title.' - Vistora')
@section('content')
    <p class="eyebrow">Collectie</p>
    <h1>{{ $collection->title }}</h1>
    @if($collection->description)<p class="intro">{{ $collection->description }}</p>@endif
    <section class="gallery" aria-label="{{ __('publication.generated.t_e478c368772600a8') }}">
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
            <p>{{ __('publication.generated.t_497c399970c13538') }}</p>
        @endforelse
    </section>
    @if($nextCursor)
        <a class="button secondary" href="{{ route('public.collections.show', [$collection, 'cursor' => $nextCursor]) }}">{{ __('publication.generated.t_78904cbff656c1f8') }}</a>
    @endif
@endsection
