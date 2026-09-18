@extends('layouts.app')

@section('title', __('ai.search.title'))

@section('content')
    <h1>{{ __('ai.search.title') }}</h1>
    <p>{{ __('ai.search.intro') }}</p>

    @include('operations._nav')
    <p>{{ __('indexwork.search_notice') }}</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif

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
        <label for="search-mode">{{ __('indexwork.mode') }}</label>
        <select id="search-mode" name="mode"><option value="semantic">{{ __('indexwork.semantic') }}</option><option value="text">{{ __('indexwork.text') }}</option></select>
        <label for="search-collection">{{ __('review.collection') }}</label>
        <select id="search-collection" name="collection">
            <option value="">{{ __('review.all') }}</option>
            @foreach($collections as $option)<option value="{{ $option->id }}" @selected($collection === $option->id)>{{ $option->title }}</option>@endforeach
        </select>
        <label>{{ __('ai.search.query') }}
            <input type="search" name="q" value="{{ $query }}" placeholder="{{ __('ai.search.placeholder') }}">
        </label>
        <p>{{ __('ai.search.provider_before', ['provider' => $provider ?: __('ai.search.unconfigured')]) }}<a href="{{ route('admin.operations.ai.edit') }}">{{ __('ai.search.settings') }}</a>{{ __('ai.search.provider_after') }}</p>
        <input type="hidden" name="provider" value="{{ $provider }}">
        <button type="submit">{{ __('ai.search.submit') }}</button>
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
                @if(isset($receipts[$result['asset_id']]))
                    <form method="post" action="{{ route('admin.operations.ai.relevance.store') }}">
                        @csrf
                        <input type="hidden" name="receipt" value="{{ $receipts[$result['asset_id']] }}">
                        <label for="grade-{{ $result['asset_id'] }}">{{ __('indexwork.label') }}</label>
                        <select id="grade-{{ $result['asset_id'] }}" name="grade" required>
                            <option value="0">{{ __('indexwork.irrelevant') }}</option><option value="1">{{ __('indexwork.partial') }}</option><option value="2">{{ __('indexwork.relevant') }}</option>
                        </select>
                        <button>{{ __('indexwork.label') }}</button>
                    </form>
                @endif
            </article>
        @empty
            @if($query !== '')<p>{{ __('ai.search.empty') }}</p>@endif
            <p>{{ $query === '' ? __('indexwork.not_searched') : __('indexwork.empty') }}</p>
        @endforelse
    </section>
    @canany(['catalogue.manage', 'users.manage'])
        <p>{{ __('indexwork.labels_notice') }} <a href="{{ route('admin.operations.ai.relevance.export') }}">{{ __('indexwork.export_labels') }}</a></p>
    @endcanany
@endsection
