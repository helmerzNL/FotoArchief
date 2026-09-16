@extends('layouts.app')
@section('title', ($asset->title ?: $asset->accession_number).' - FotoArchief')
@section('content')
    <p class="eyebrow">Foto</p>
    <h1>{{ $asset->title ?: $asset->accession_number }}</h1>
    @if($file)
        <img class="preview" src="{{ route('public.photo.media', [$publication, 'preview1200']) }}" alt="">
    @endif
    @if($asset->description)<p class="intro">{{ $asset->description }}</p>@endif
    @if($publication->credit_line)<p>Bronvermelding: {{ $publication->credit_line }}</p>@endif
    <p>Permalink: <code>{{ route('public.photo', $publication) }}</code></p>
@endsection
