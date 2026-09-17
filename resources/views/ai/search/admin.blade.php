@extends('layouts.app')

@section('title', __('ai.search.title'))

@section('content')
    <h1>{{ __('ai.search.title') }}</h1>
    <p>{{ __('ai.search.intro') }}</p>

    @include('operations._nav')

    @if($errors->any())
        <div class="card" role="alert">
            <strong>{{ __('ai.search.failed') }}</strong>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="GET" action="{{ route('admin.operations.ai.search') }}" class="card">
        <label>{{ __('ai.search.query') }}
            <input type="search" name="q" value="{{ $query }}" placeholder="{{ __('ai.search.placeholder') }}">
        </label>
        <p>{{ __('ai.search.provider_before', ['provider' => $provider ?: __('ai.search.unconfigured')]) }}<a href="{{ route('admin.operations.ai.edit') }}">{{ __('ai.search.settings') }}</a>{{ __('ai.search.provider_after') }}</p>
        <input type="hidden" name="provider" value="{{ $provider }}">
        <button type="submit" @disabled(! ($settings['embeddings_ready'] ?? false))>{{ __('ai.search.submit') }}</button>
        @unless($settings['embeddings_ready'] ?? false)
            <p role="alert">{{ __('ai.search.not_ready') }}</p>
        @endunless
    </form>

    <section class="card">
        <h2>{{ __('ai.search.results') }}</h2>
        @forelse($results as $result)
            <article>
                <h3><a href="{{ route('admin.assets.show', $result['asset_id']) }}">{{ $result['accession_number'] }}</a></h3>
                <p>{{ $result['title'] }}</p>
                <p>{{ __('ai.search.score', ['score' => number_format($result['score'], 3), 'space' => $result['model_space']]) }}</p>
            </article>
        @empty
            <p>{{ __('ai.search.empty') }}</p>
        @endforelse
    </section>
@endsection
