@extends('layouts.app')

@section('title', __('ai.bulk_tags.title'))

@section('content')
    @php
        $minimumLabel = number_format($minimum, 2, ',', '.');
    @endphp
    <h1>{{ __('ai.bulk_tags.title') }}</h1>
    <p>{{ __('ai.bulk_tags.intro', ['minimum' => $minimumLabel]) }}</p>

    @include('operations._nav')

    @if($errors->any())
        <div class="card" role="alert">
            <strong>{{ __('ai.suggestions.failed') }}</strong>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($selected->isEmpty())
        <p>{{ __('ai.bulk_tags.none') }}</p>
    @else
        <section class="card">
            <h2>{{ __('ai.bulk_tags.summary', ['tags' => $selected->count(), 'photos' => $photoCount]) }}</h2>
            @if($moreThanMax)
                <p role="status">{{ __('ai.bulk_tags.more', ['max' => $max]) }}</p>
            @endif
            <ul>
                @foreach($tagCounts as $tagName => $tagTotal)
                    <li>{{ $tagName }} · {{ __('ai.bulk_tags.count') }}: {{ $tagTotal }}</li>
                @endforeach
            </ul>
        </section>
        <form method="POST" action="{{ route('admin.operations.ai.suggestions.tags.accept') }}" class="card">
            @csrf
            <input type="hidden" name="min_confidence" value="{{ $minimum }}">
            @foreach($selected as $suggestion)
                <input type="hidden" name="selected[]" value="{{ $suggestion->id }}">
                <input type="hidden" name="versions[{{ $suggestion->id }}]" value="{{ $suggestion->asset?->lock_version }}">
            @endforeach
            <label><input type="checkbox" name="confirm" value="1" required> {{ __('ai.bulk_tags.confirm') }}</label>
            <button type="submit">{{ __('ai.bulk_tags.submit') }}</button>
        </form>
    @endif
    <p><a href="{{ route('admin.operations.ai.suggestions.index', $filters) }}">{{ __('ai.bulk_tags.back') }}</a></p>
@endsection
