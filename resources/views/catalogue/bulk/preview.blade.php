@extends('layouts.app')
@section('title', __('daily.preview'))
@section('content')
<h1>{{ __('daily.preview') }}</h1>
<p>{{ __('daily.bulk_hint') }}</p>
@foreach($rows as $row)
    <section class="card">
        <h2>{{ $row['asset']->accession_number }}</h2>
        <h3>{{ __('daily.current') }}</h3>
        <pre>{{ json_encode($row['before'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        <h3>{{ __('daily.proposed') }}</h3>
        <pre>{{ json_encode($row['after'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </section>
@endforeach
<form method="post" action="{{ route('catalogue.bulk.apply') }}">
    @csrf
    <input type="hidden" name="receipt" value="{{ $receipt }}">
    <label><input type="checkbox" name="confirm" value="1" required> {{ __('daily.confirm') }}</label>
    <button>{{ __('daily.apply') }}</button>
</form>
@endsection
