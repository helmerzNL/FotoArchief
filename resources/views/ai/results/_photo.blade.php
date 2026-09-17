<section class="card" id="ai-results">
    <h2>{{ __('ai.results.photo_title') }}</h2>
    <p>{{ __('ai.results.photo_intro') }}</p>
    @php
        $runTypeLabels = ['embedding' => __('ai.results.run_types.embedding'), 'image_analysis' => __('ai.results.run_types.image_analysis')];
        $statusLabels = ['succeeded' => __('ai.results.statuses.succeeded'), 'failed' => __('ai.results.statuses.failed'), 'queued' => __('ai.results.statuses.queued'), 'running' => __('ai.results.statuses.running'), 'cancelled' => __('ai.results.statuses.cancelled')];
        $suggestionTypeLabels = ['description' => __('ai.results.suggestion_types.description'), 'tag' => __('ai.results.suggestion_types.tag'), 'object' => __('ai.results.suggestion_types.object')];
        $reviewStatusLabels = ['pending' => __('ai.results.review_statuses.pending'), 'accepted' => __('ai.results.review_statuses.accepted'), 'rejected' => __('ai.results.review_statuses.rejected'), 'superseded' => __('ai.results.review_statuses.superseded')];
    @endphp
    @forelse($aiRuns as $aiRun)
        <article style="border-block-end: 1px solid var(--border); padding-block: 1rem;">
            <h3>{{ $runTypeLabels[$aiRun->run_type] ?? $aiRun->run_type }}</h3>
            <p>{{ __('ai.results.provider_model', ['provider' => $aiRun->provider_kind, 'model' => $aiRun->model_id]) }}@if($aiRun->model_version) ({{ $aiRun->model_version }})@endif</p>
            <p>{{ __('ai.results.stored_status', ['stored' => $aiRun->finished_at ?? $aiRun->created_at, 'status' => $statusLabels[$aiRun->status] ?? $aiRun->status]) }}</p>
            <p>{{ __('ai.results.source_revision', ['source' => $aiRun->source_asset_lock_version, 'current' => $asset->lock_version]) }}</p>
            @if($aiRun->source_asset_lock_version !== $asset->lock_version)
                <p>{{ __('ai.results.stale_notice') }}</p>
            @endif
            @if($aiRun->run_type === 'embedding')
                <p>{{ __('ai.results.embedding_notice') }}</p>
                <p>{{ __('ai.results.model_space', ['space' => $aiRun->model_space, 'dimensions' => $aiRun->result_summary['dimensions'] ?? __('ai.results.not_reported')]) }}</p>
            @else
                @forelse($aiRun->suggestions as $suggestion)
                    <div style="margin-block: 1rem; overflow-wrap: anywhere;">
                        <h4>{{ $suggestionTypeLabels[$suggestion->suggestion_type] ?? $suggestion->suggestion_type }}</h4>
                        <p style="white-space: pre-wrap;">{{ $suggestion->value }}</p>
                        <p>{{ __('ai.results.review') }} <strong>{{ $reviewStatusLabels[$suggestion->review_status] ?? $suggestion->review_status }}</strong>
                            @if($suggestion->reviewed_at) · {{ $suggestion->reviewed_at }}@endif
                        </p>
                        @if($suggestion->review_note)<p>{{ __('ai.results.review_note', ['note' => $suggestion->review_note]) }}</p>@endif
                        @can('update', $asset)
                            @if($suggestion->review_status === 'pending')
                                @if(in_array($suggestion->suggestion_type, ['description', 'tag'], true))
                                    <form method="post" action="{{ route('admin.operations.ai.suggestions.accept', $suggestion) }}">
                                        @csrf
                                        <input type="hidden" name="return_to" value="asset">
                                        <input type="hidden" name="lock_version" value="{{ $asset->lock_version }}">
                                        <button>{{ __('ai.results.accept') }}</button>
                                    </form>
                                @endif
                                <form method="post" action="{{ route('admin.operations.ai.suggestions.reject', $suggestion) }}">
                                    @csrf
                                    <input type="hidden" name="return_to" value="asset">
                                    <label>{{ __('ai.results.reject_label') }} <input name="review_note" maxlength="500"></label>
                                    <button class="secondary">{{ __('ai.results.reject') }}</button>
                                </form>
                            @endif
                        @endcan
                    </div>
                @empty
                    <p>{{ $aiRun->status === 'succeeded' ? __('ai.results.empty_analysis_success') : __('ai.results.empty_analysis_pending') }}</p>
                @endforelse
            @endif
        </article>
    @empty
        <p>{{ __('ai.results.empty_photo') }}</p>
    @endforelse
    {{ $aiRuns->links() }}
</section>
