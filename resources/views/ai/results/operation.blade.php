@extends('layouts.app')
@section('title', __('ai.results.operation_title'))
@section('content')
    @include('operations._nav')
    <h1>{{ __('ai.results.operation_heading') }}</h1>
    <p>{{ __('ai.results.operation_summary', ['run' => $run->id, 'type' => $run->operation_type]) }}</p>
    <p>{{ __('ai.results.operation_intro') }}</p>
    <p>{{ __('ai.results.operation_refresh') }}</p>
    <section class="card">
        <h2>{{ __('ai.results.operation_successful') }}</h2>
        <ul>
            @forelse($assets as $asset)
                <li><a href="{{ route('admin.assets.show', $asset) }}#ai-results">{{ $asset->accession_number }} · {{ $asset->title ?: __('ai.results.untitled') }} — {{ __('ai.results.view_for_photo') }}</a></li>
            @empty
                <li>{{ __('ai.results.operation_empty') }}</li>
            @endforelse
        </ul>
        {{ $assets->links() }}
    </section>
    <a href="{{ route('admin.operations.runs.index') }}">{{ __('ai.results.back_to_runs') }}</a>
@endsection
