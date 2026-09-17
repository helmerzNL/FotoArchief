@extends('layouts.app')

@section('title', __('ai.suggestions.title'))

@section('content')
    <h1>{{ __('ai.suggestions.title') }}</h1>
    <p>{{ __('ai.suggestions.intro') }}</p>

    @include('operations._nav')

    @if(session('status'))
        <div class="card" role="status">{{ session('status') }}</div>
    @endif

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

    <section class="card">
        <h2>{{ __('ai.suggestions.open') }}</h2>
        @forelse($suggestions as $suggestion)
            <article style="border-block-end:1px solid var(--border);padding:1rem 0;">
                <h3>{{ $suggestion->asset?->accession_number }} · {{ $suggestion->suggestion_type }}</h3>
                <p>{{ $suggestion->value }}</p>
                <p>{{ __('ai.suggestions.source', ['version' => $suggestion->source_asset_lock_version, 'checksum' => $suggestion->source_file_sha256]) }}</p>
                <form method="POST" action="{{ route('admin.operations.ai.suggestions.accept', $suggestion) }}" style="display:inline-block;margin-inline-end:1rem;">
                    @csrf
                    <input type="hidden" name="lock_version" value="{{ $suggestion->asset?->lock_version }}">
                    <button type="submit">{{ __('ai.suggestions.accept') }}</button>
                </form>
                <form method="POST" action="{{ route('admin.operations.ai.suggestions.reject', $suggestion) }}" style="display:inline-block;">
                    @csrf
                    <input type="text" name="review_note" placeholder="{{ __('ai.suggestions.note_placeholder') }}" maxlength="500">
                    <button type="submit">{{ __('ai.suggestions.reject') }}</button>
                </form>
            </article>
        @empty
            <p>{{ __('ai.suggestions.empty') }}</p>
        @endforelse

        {{ $suggestions->links() }}
    </section>
@endsection
