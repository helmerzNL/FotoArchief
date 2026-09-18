@extends('layouts.app')
@section('title', __('daily.compare'))
@section('content')
<h1>{{ __('daily.compare') }}</h1>
<p>{{ __('daily.conflict_hint', ['revision' => $conflict->lock_version]) }}</p>
@php($labels = __('daily.fields'))
<form method="post" action="{{ route('admin.assets.resolve', $asset) }}">
    @csrf
    <input type="hidden" name="receipt" value="{{ $receipt }}">
    <div class="table-scroll">
        <table>
            <thead><tr><th>{{ __('daily.field') }}</th><th>{{ __('daily.current') }}</th><th>{{ __('daily.proposed') }}</th><th>{{ __('daily.choose') }}</th></tr></thead>
            <tbody>
                @foreach($current as $field => $value)
                    <tr>
                        <th>{{ $labels[$field] }}</th><td>{{ $value }}</td><td>{{ $proposed[$field] ?? '' }}</td>
                        <td>
                            <label for="choice-{{ $field }}">{{ $labels[$field] }}</label>
                            <select id="choice-{{ $field }}" name="choices[{{ $field }}]" required>
                                <option value="">{{ __('daily.choose') }}</option>
                                <option value="current">{{ __('daily.current') }}</option>
                                <option value="proposed">{{ __('daily.proposed') }}</option>
                            </select>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <label><input type="checkbox" name="confirm" value="1" required> {{ __('daily.confirm') }}</label>
    <button>{{ __('daily.apply') }}</button>
</form>
@endsection
