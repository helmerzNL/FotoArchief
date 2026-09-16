@extends('layouts.app')
@section('title', 'Collecties - FotoArchief')
@section('content')
    <p class="eyebrow">Publieke collectie</p>
    <h1>Collecties</h1>
    <ul class="asset-list">
        @forelse($collections as $collection)
            <li><a href="{{ route('public.collections.show', $collection) }}">{{ $collection->title }}</a>
                @if($collection->description)<small>{{ $collection->description }}</small>@endif
            </li>
        @empty
            <li>Er zijn nog geen publieke collecties.</li>
        @endforelse
    </ul>
@endsection
