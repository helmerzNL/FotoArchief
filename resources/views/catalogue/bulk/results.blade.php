@extends('layouts.app')
@section('title', __('daily.results'))
@section('content')
<h1>{{ __('daily.results') }}</h1>
<ul>
@foreach($results as $result)
    <li>
        @if($result['ok'])
            <a href="{{ route('admin.assets.show', $result['id']) }}">{{ $result['label'] }}</a>
        @else
            {{ $result['label'] }}
        @endif
        {{ $result['message'] }}
    </li>
@endforeach
</ul>
<a href="{{ route('admin.assets.index') }}">{{ __('daily.back') }}</a>
@endsection
