@extends('layouts.app')
@section('title', __('uploads.title'))
@section('content')
<h1>{{ __('uploads.title') }}</h1>
<p>{{ __('uploads.intro') }}</p>
<section class="card">
    @php($endpoint = route('admin.uploads.store'))
    @php($existing = false)
    @include('admin.uploads.form')
</section>
<ul>
@forelse($sessions as $session)
    <li><a href="{{ route('admin.uploads.show', $session) }}">{{ $session->id }}</a> — {{ __('uploads.expires') }}: {{ $session->expires_at }}</li>
@empty
    <li>{{ __('uploads.empty') }}</li>
@endforelse
</ul>
{{ $sessions->links() }}
@endsection
