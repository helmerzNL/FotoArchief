@extends('layouts.app')
@section('title', 'Collecties - Vistora')
@section('content')
    <p class="eyebrow">{{ __('publication.generated.t_05722e41037c766f') }}</p>
    <h1>Collecties</h1>
    <ul class="asset-list">
        @forelse($collections as $collection)
            <li><a href="{{ route('public.collections.show', $collection) }}">{{ $collection->title }}</a>
                @if($collection->description)<small>{{ $collection->description }}</small>@endif
            </li>
        @empty
            <li>{{ __('publication.generated.t_7a80d3ab09b3b385') }}</li>
        @endforelse
    </ul>
@endsection
