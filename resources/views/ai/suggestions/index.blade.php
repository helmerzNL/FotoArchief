@extends('layouts.app')

@section('title', __('ai.suggestions.title'))

@section('content')
    @php
        $reviewStates = [
            'reviewable' => __('review.states.reviewable'),
            'pending' => __('review.states.pending'),
            'accepted' => __('review.states.accepted'),
            'rejected' => __('review.states.rejected'),
            'superseded' => __('review.states.superseded'),
            'reverted' => __('review.states.reverted'),
        ];
    @endphp
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

    <form method="GET" class="card">
        <h2>{{ __('review.filters') }}</h2>
        <label>{{ __('review.status') }} <select name="status">
            @foreach($reviewStates as $state => $label)
                <option value="{{ $state }}" @selected(($filters['status'] ?? 'reviewable') === $state)>{{ $label }}</option>
            @endforeach
        </select></label>
        <label>{{ __('review.collection') }} <select name="collection"><option value="">{{ __('review.all') }}</option>
            @foreach($collections as $collection)
                <option value="{{ $collection->id }}" @selected(($filters['collection'] ?? '') === $collection->id)>{{ $collection->title }}</option>
            @endforeach
        </select></label>
        <label>{{ __('review.provider') }} <input name="provider" value="{{ $filters['provider'] ?? '' }}" maxlength="100"></label>
        <label>{{ __('review.from') }} <input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
        <label>{{ __('review.until') }} <input type="date" name="until" value="{{ $filters['until'] ?? '' }}"></label>
        <button>{{ __('review.apply') }}</button>
    </form>
    @if(session('review_results'))
        <section class="card" role="status"><h2>{{ __('review.results') }}</h2><ul>
            @foreach(session('review_results') as $result)
                <li>{{ $result['id'] }}: {{ $result['message'] }}</li>
            @endforeach
        </ul></section>
    @endif
    <form id="bulk-review" method="POST" action="{{ route('admin.operations.ai.suggestions.bulk') }}" class="card">
        @csrf
        <label>{{ __('review.decision') }} <select name="decision"><option value="accept">{{ __('ai.suggestions.accept') }}</option><option value="reject">{{ __('ai.suggestions.reject') }}</option></select></label>
        <label><input type="checkbox" name="confirm" value="1" required> {{ __('review.confirm') }}</label>
        <button>{{ __('review.bulk') }}</button>
    </form>
    <section class="card">
        <h2>{{ __('ai.suggestions.open') }}</h2>
        @forelse($suggestions as $suggestion)
            <article style="border-block-end:1px solid var(--border);padding:1rem 0;">
                <h3><a href="{{ route('admin.assets.show', $suggestion->asset_id) }}">{{ $suggestion->asset?->accession_number }}</a> · {{ $suggestion->suggestion_type }}</h3>
                <p>{{ $suggestion->run?->provider_name }} · {{ $suggestion->created_at }} · {{ $reviewStates[$suggestion->review_status] ?? $suggestion->review_status }}</p>
                <h4>{{ __('review.current') }}</h4>
                <p>{{ $suggestion->asset?->description }}</p>
                <p>{{ $suggestion->asset?->tags->pluck('name')->join(', ') }}</p>
                <h4>{{ __('review.proposed') }}</h4>
                <p>{{ $suggestion->value }}</p>
                <p>{{ __('ai.suggestions.source', ['version' => $suggestion->source_asset_lock_version, 'checksum' => $suggestion->source_file_sha256]) }}</p>
                @if($suggestion->canReview())
                <label><input form="bulk-review" type="checkbox" name="selected[]" value="{{ $suggestion->id }}"> {{ __('review.select', ['id' => $suggestion->id]) }}</label>
                <input form="bulk-review" type="hidden" name="versions[{{ $suggestion->id }}]" value="{{ $suggestion->asset?->lock_version }}">
                <form method="POST" action="{{ route('admin.operations.ai.suggestions.accept', $suggestion) }}" style="display:inline-block;margin-inline-end:1rem;">
                    @csrf
                    <input type="hidden" name="lock_version" value="{{ $suggestion->asset?->lock_version }}">
                    @if($suggestion->suggestion_type === 'description')
                        <label>{{ __('review.description') }} <textarea name="edited_description" maxlength="10000" required>{{ $suggestion->value }}</textarea></label>
                    @else
                        <p>{{ __('review.tag_effect') }}</p>
                    @endif
                    <button type="submit">{{ __('ai.suggestions.accept') }}</button>
                </form>
                @endif
                @if($suggestion->review_status === 'accepted' && $suggestion->reviewed_by_user_id === auth()->id() && $suggestion->acceptance_receipt)
                    <form method="POST" action="{{ route('admin.operations.ai.suggestions.undo', $suggestion) }}">
                        @csrf
                        <input type="hidden" name="lock_version" value="{{ $suggestion->asset?->lock_version }}">
                        <button>{{ __('review.undo') }}</button>
                    </form>
                @endif
                @if($suggestion->canReview())
                <form method="POST" action="{{ route('admin.operations.ai.suggestions.reject', $suggestion) }}" style="display:inline-block;">
                    @csrf
                    <input type="text" name="review_note" placeholder="{{ __('ai.suggestions.note_placeholder') }}" maxlength="500">
                    <button type="submit">{{ __('ai.suggestions.reject') }}</button>
                </form>
                @endif
            </article>
        @empty
            <p>{{ __('ai.suggestions.empty') }}</p>
        @endforelse

        {{ $suggestions->links() }}
    </section>
@endsection
