@extends('layouts.app')
@section('title', __('workbench.detail'))
@section('content')
    @include('operations._nav')
    <h1>{{ __('workbench.detail') }} {{ $run->id }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
    <section class="card">
        <p>{{ $run->operation_type }} · {{ $run->status }}</p>
        <p>{{ __('workbench.timings') }}: {{ $run->created_at }} / {{ $run->started_at ?? '-' }} / {{ $run->finished_at ?? '-' }}</p>
        <p>{{ __('workbench.attempts') }}: {{ $run->attempts }} · {{ $run->processed_items }} / {{ $run->total_items }}</p>
        @if($run->error_message)<p role="alert">{{ $run->error_message }}</p>@endif
        <h2>{{ __('workbench.settings') }}</h2>
        <pre class="revision">{{ json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        @can('users.manage')
            @if(in_array($run->operation_type, \App\Modules\ArchiveOperations\Services\OperationWorkbenchService::PAUSABLE, true))
                <p>{{ __('workbench.pause_notice') }}</p>
                @if($run->pause_requested && $run->status === 'running')<p role="status">{{ __('workbench.pausing') }}</p>@endif
                @if(in_array($run->status, ['running', 'queued', 'paused'], true))
                    <form method="post" action="{{ route('admin.operations.runs.control', $run) }}">
                        @csrf
                        <input type="hidden" name="action" value="{{ $run->status === 'paused' ? 'resume' : 'pause' }}">
                        <button>{{ $run->status === 'paused' ? __('workbench.resume') : __('workbench.pause') }}</button>
                    </form>
                @endif
            @endif
        @endcan
    </section>
    <section class="card">
        <h2>{{ __('workbench.items') }}</h2>
        @php($states = ['processed' => __('workbench.processed'), 'failed' => __('workbench.failed'), 'waiting' => __('workbench.waiting'), 'skipped' => __('workbench.skipped')])
        <form method="post" action="{{ route('admin.operations.runs.retry-selected', $run) }}">
            @csrf
            @forelse($items as $item)
                <article>
                    <h3>@if($item['asset'])<a href="{{ route('admin.assets.show', $item['asset']) }}">{{ $item['asset']->accession_number }}</a>@else{{ $item['id'] }}@endif</h3>
                    <p>{{ $states[$item['status']] }} · {{ $item['reason'] }}</p>
                    @if($item['status'] === 'failed' && $run->isFinished())
                        @can('users.manage')<label><input type="checkbox" name="selected[]" value="{{ $item['id'] }}"> {{ __('workbench.select_failed') }}</label>@endcan
                    @endif
                </article>
            @empty
                <p>{{ __('workbench.no_selection') }}</p>
            @endforelse
            @if($run->isFinished() && in_array($run->operation_type, ['ai.analysis', 'ai.index'], true))
                @can('users.manage')
                    <label><input name="confirm" type="checkbox" value="1" required> {{ __('workbench.confirm_retry') }}</label>
                    <button>{{ __('workbench.retry_selected') }}</button>
                @endcan
            @endif
        </form>
        {{ $items->links() }}
    </section>
    <section class="card"><h2>{{ __('workbench.audit') }}</h2>
        @foreach($events as $event)<p>{{ $event->created_at }} · {{ $event->event_type }} · {{ $event->message }}</p>@endforeach
        {{ $events->links() }}
    </section>
@endsection
