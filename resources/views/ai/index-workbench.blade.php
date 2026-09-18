@extends('layouts.app')
@section('title', __('indexwork.title'))
@section('content')
    @include('operations._nav')
    <h1>{{ __('indexwork.title') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
    @unless($available)<p role="alert">{{ __('ai.errors.pgvector_unavailable') }}</p>@endunless
    <p>{{ __('indexwork.space') }}: {{ $settings['embeddings_provider'] ?? '-' }} / {{ $settings['embeddings_model'] ?? '-' }} / {{ $active?->model_space ?? '-' }}</p>
    <form method="get" class="card">
        <label for="index-collection">{{ __('review.collection') }}</label>
        <select id="index-collection" name="collection">
            <option value="">{{ __('review.all') }}</option>
            @foreach($collections as $option)<option value="{{ $option->id }}" @selected($collection === $option->id)>{{ $option->title }}</option>@endforeach
        </select>
        <button>{{ __('review.apply') }}</button>
    </form>
    @php($states = ['current' => __('indexwork.current'), 'stale' => __('indexwork.stale'), 'missing' => __('indexwork.missing'), 'excluded' => __('indexwork.excluded'), 'failed' => __('indexwork.failed')])
    <section class="card">
        <h2>{{ __('indexwork.coverage') }}</h2>
        @foreach($states as $key => $label)<p>{{ $label }}: {{ $counts[$key] ?? 0 }}</p>@endforeach
        <p>{{ __('indexwork.coverage_notice') }}</p>
        <form method="post" action="{{ route('admin.operations.ai.workbench.repair') }}">
            @csrf
            <input type="hidden" name="collection" value="{{ $collection }}">
            <input type="hidden" name="head" value="{{ $active?->id }}">
            <input type="hidden" name="configuration" value="{{ $configuration }}">
            @foreach($items as $item)
                <p><a href="{{ route('admin.assets.show', $item->id) }}">{{ $item->accession_number }}</a> · {{ $item->title }} · {{ $states[$item->coverage_status] }}
                    @if($collection && in_array($item->coverage_status, ['missing', 'stale'], true))
                        <label><input type="checkbox" name="selected[]" value="{{ $item->id }}"> {{ __('indexwork.select') }}</label>
                    @endif
                </p>
            @endforeach
            @if($collection)
                <label><input type="checkbox" name="confirm" value="1" required> {{ __('indexwork.confirm') }}</label>
                <button @disabled(! $available)>{{ __('indexwork.repair') }}</button>
            @endif
        </form>
        {{ $items->links() }}
    </section>
    <section class="card"><h2>{{ __('indexwork.generations') }}</h2>
        @foreach($generations as $generation)
            <article>
                <h3>{{ $generation->id }} · {{ $generation->status }}</h3>
                <p>{{ $generation->provider_kind }} / {{ $generation->model_space }} / {{ $generation->dimensions }}</p>
                @if($generation->operationRun)
                    <p><a href="{{ route('admin.operations.runs.show', $generation->operationRun) }}">{{ $generation->operationRun->id }}</a> · {{ $generation->operationRun->status }} · {{ $generation->operationRun->processed_items }} / {{ $generation->operationRun->total_items }}</p>
                    @foreach($generation->operationRun->auditEvents as $event)<p>{{ $event->event_type }} · {{ $event->message }}</p>@endforeach
                    @if($generation->status === 'building' && in_array($generation->operationRun->status, ['failed', 'completed'], true))
                        @can('users.manage')
                            <form method="post" action="{{ route('admin.operations.ai.workbench.activate', $generation) }}">
                                @csrf
                                <label><input type="checkbox" name="confirm" value="1" required> {{ __('indexwork.confirm_activation') }}</label>
                                <button>{{ __('indexwork.activate') }}</button>
                            </form>
                        @endcan
                    @endif
                @endif
            </article>
        @endforeach
        {{ $generations->links() }}
    </section>
@endsection
