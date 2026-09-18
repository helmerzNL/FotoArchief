@extends('layouts.app')
@section('title', __('workbench.audit'))
@section('content')
    @include('operations._nav')
    <h1>{{ __('workbench.audit') }}</h1>
    @if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
    <form method="get" class="card">
        <label>{{ __('workbench.asset') }} <input name="asset" value="{{ $filters['asset'] ?? '' }}"></label>
        <label>{{ __('workbench.run') }} <input name="run" value="{{ $filters['run'] ?? '' }}"></label>
        <label>{{ __('workbench.actor') }} <input name="actor" value="{{ $filters['actor'] ?? '' }}"></label>
        <label>{{ __('workbench.event') }} <input name="event" value="{{ $filters['event'] ?? '' }}"></label>
        <label>{{ __('review.from') }} <input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
        <label>{{ __('review.until') }} <input type="date" name="until" value="{{ $filters['until'] ?? '' }}"></label>
        <button>{{ __('review.apply') }}</button>
        <button name="export" value="jsonl">{{ __('workbench.export') }}</button>
        <p>{{ __('workbench.export_notice') }}</p>
    </form>
    <section class="card">
        @foreach($events as $event)
            <article><p>{{ $event->created_at }} · {{ $event->event_type }} · {{ $event->actor_user_id }}</p>
                <p>{{ $event->asset_id }}</p>
                @if($event->operation_run_id)<a href="{{ route('admin.operations.runs.show', $event->operation_run_id) }}">{{ $event->operation_run_id }}</a>@endif
            </article>
        @endforeach
        {{ $events->links() }}
    </section>
@endsection
