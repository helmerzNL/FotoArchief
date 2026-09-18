@extends('layouts.app')
@section('title', __('evidence.timeline'))
@section('content')
@include('operations._nav')
<h1>{{ __('evidence.timeline') }} · {{ $record->title }}</h1>
<p>{{ __('evidence.timeline_notice') }}</p>
<ol>
@foreach($events as $event)
    <li>{{ $event->created_at }} · {{ __('evidence.events')[$event->event_type] }} · {{ $event->severity }}
        <br>{{ __('evidence.event_id') }}: {{ $event->notification_id }} · {{ __('evidence.actor') }}: {{ $event->actor_user_id ?? '—' }}
    </li>
@endforeach
</ol>
{{ $events->links() }}
@endsection
