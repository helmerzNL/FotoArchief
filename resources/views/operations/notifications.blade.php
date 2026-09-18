@extends('layouts.app')
@section('title', __('evidence.notifications'))
@section('content')
<h1>{{ __('evidence.notifications') }}</h1>
<p>{{ __('evidence.notification_notice') }}</p>
@forelse($runs as $run)
    <article class="card">
        <h2>{{ __('evidence.run') }} {{ $run->id }} · {{ __('evidence.statuses')[$run->status] }}</h2>
        <p>{{ ($read[$run->id] ?? null) === $fingerprints[$run->id] ? __('evidence.read') : __('evidence.unread') }}</p>
        <a href="{{ route('admin.operations.runs.show', $run) }}">{{ __('evidence.details') }}</a>
        @if(($read[$run->id] ?? null) !== $fingerprints[$run->id])
        <form method="post" action="{{ route('admin.operations.notifications.read', $run) }}">
            @csrf<input type="hidden" name="fingerprint" value="{{ $fingerprints[$run->id] }}">
            <button>{{ __('evidence.mark_read') }}</button>
        </form>
        @endif
    </article>
@empty<p>{{ __('evidence.empty') }}</p>@endforelse
{{ $runs->links() }}
@endsection
