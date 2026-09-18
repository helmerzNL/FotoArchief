@extends('layouts.app')
@section('title', __('evidence.title'))
@section('content')
@include('operations._nav')
<h1>{{ __('evidence.title') }}</h1>
<p>{{ __('evidence.notice') }}</p>
<form class="card" method="post" action="{{ route('admin.operations.evidence.store') }}">
    @csrf
    <label for="proof-version">{{ __('evidence.version') }}</label><input id="proof-version" name="version" value="{{ trim(file_get_contents(base_path('VERSION'))) }}" required maxlength="32">
    <label for="proof-environment">{{ __('evidence.environment') }}</label><select id="proof-environment" name="environment">@foreach(__('evidence.environments') as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
    <label for="proof-kind">{{ __('evidence.kind') }}</label><select id="proof-kind" name="kind">@foreach(__('evidence.kinds') as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
    <label for="proof-result">{{ __('evidence.result') }}</label><select id="proof-result" name="result">@foreach(__('evidence.results') as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
    <label for="proof-reference">{{ __('evidence.reference') }}</label><input id="proof-reference" name="reference" maxlength="500" required>
    <label><input type="checkbox" name="confirm" value="1" required>{{ __('evidence.confirm') }}</label>
    <button>{{ __('evidence.record') }}</button>
</form>
@foreach($evidence as $entry)
    <article class="card"><h2>{{ $entry->version }} · {{ __('evidence.results')[$entry->result] }}</h2>
        <p>{{ $entry->created_at }} · {{ __('evidence.environments')[$entry->environment] }} · {{ __('evidence.sources')[$entry->source] }} · {{ __('evidence.kinds')[$entry->kind] }}</p>
        <p>{{ $entry->reference }} · {{ __('evidence.actor') }}: {{ $entry->actor_user_id ?? '—' }}</p>
    </article>
@endforeach
{{ $evidence->links() }}
<h2>{{ __('evidence.support') }}</h2>
<p>{{ __('evidence.support_notice') }}</p>
<form method="post" action="{{ route('admin.operations.support') }}">
    @csrf
    <fieldset><legend>{{ __('evidence.run') }}</legend>@foreach($runs as $run)<label><input type="checkbox" name="runs[]" value="{{ $run->id }}">{{ $run->id }} · {{ $run->status }}</label>@endforeach</fieldset>
    <fieldset><legend>{{ __('recovery.incidents') }}</legend>@foreach($incidents as $incident)<label><input type="checkbox" name="incidents[]" value="{{ $incident->id }}">{{ $incident->id }} · {{ $incident->status }}</label>@endforeach</fieldset>
    <fieldset><legend>{{ __('recovery.reports') }}</legend>@foreach($checks as $check)<label><input type="checkbox" name="checks[]" value="{{ $check->id }}">{{ $check->id }} · {{ $check->created_at }}</label>@endforeach</fieldset>
    <label><input type="checkbox" name="confirm" value="1" required>{{ __('evidence.confirm_support') }}</label>
    <button>{{ __('evidence.download') }}</button>
</form>
@endsection
