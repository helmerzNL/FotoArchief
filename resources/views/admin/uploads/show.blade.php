@extends('layouts.app')
@section('title', __('uploads.title'))
@section('content')
<h1>{{ __('uploads.title') }} — {{ $session->id }}</h1>
<a href="{{ route('admin.uploads.show', $session) }}">{{ __('uploads.refresh') }}</a>
<section class="card">
    <h2>{{ __('uploads.summary') }}</h2>
    <ul>@foreach($counts as $state => $count)<li>{{ \App\Modules\Ingest\Models\UploadSessionItem::stateLabel($state) }}: {{ $count }}</li>@endforeach</ul>
    <p>{{ __('uploads.expires') }}: {{ $session->expires_at }}</p>
    @if($session->closed_at === null && $session->purged_at === null && $session->expires_at->isFuture())
        <p>{{ __('uploads.reselect') }}</p>
        @php($endpoint = route('admin.uploads.show', $session))
        @php($existing = true)
        @include('admin.uploads.form')
        <form method="post" action="{{ route('admin.uploads.close', $session) }}">@csrf
            <label><input type="checkbox" name="confirm" value="1" required> {{ __('uploads.confirm') }}</label>
            <button>{{ __('uploads.close') }}</button>
        </form>
    @else
        <p>{{ __('uploads.closed') }}</p>
    @endif
</section>
<table>
    <thead><tr><th>{{ __('uploads.file') }}</th><th>{{ __('uploads.state') }}</th></tr></thead>
    <tbody>
    @foreach($session->items as $item)
        <tr><td>{{ $item->filename }}</td><td>
            {{ \App\Modules\Ingest\Models\UploadSessionItem::stateLabel($item->upload?->status ?? $item->status) }}
            @if($item->error_code)<p>{{ $item->errorLabel() }}</p>@endif
            @if($item->upload?->failure_reason)<p>{{ $item->upload->failure_reason }}</p>@endif
            @if($item->upload?->asset)<a href="{{ route('admin.assets.show', $item->upload->asset) }}">{{ __('uploads.view') }}</a>@endif
            @php($retryable = ($item->upload?->status ?? $item->status) === 'failed')
            @if($retryable)
                <form method="post" action="{{ route('admin.uploads.retry', [$session, $item]) }}">@csrf
                    <label><input type="checkbox" name="confirm" value="1" required> {{ __('uploads.confirm') }}</label>
                    <button>{{ __('uploads.retry') }}</button>
                </form>
            @endif
        </td></tr>
    @endforeach
    </tbody>
</table>
@endsection
