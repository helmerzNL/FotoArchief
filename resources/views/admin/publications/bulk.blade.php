@extends('layouts.app')
@section('title', __('publishwork.decision'))
@section('content')
    <h1>{{ __('daily.preview') }}</h1>
    <p>{{ $data['decision'] === 'publish' ? __('publishwork.publish') : __('publishwork.reject') }}</p>
    <p>{{ $data['reason'] ?? '' }}</p>
    @foreach($rows as $row)
        <h2>{{ $row['asset']->accession_number }} &middot; {{ $row['asset']->title }}</h2>
        @include('admin.publications.checklist', ['checks' => $row['checks']])
    @endforeach
    <form method="post" action="{{ route('admin.publications.bulk.apply') }}">
        @csrf
        <input type="hidden" name="receipt" value="{{ $receipt }}">
        <label><input type="checkbox" name="confirm" value="1" required> {{ __('daily.confirm') }}</label>
        <button type="submit">{{ __('daily.apply') }}</button>
    </form>
@endsection
