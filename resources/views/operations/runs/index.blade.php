@extends('layouts.app')
@section('title', 'Achtergrondtaken - FotoArchief Operaties')
@section('content')
    @include('operations._nav')
    <p class="eyebrow">{{ __('operations.generated.t_a40762c3c9123975') }}</p>
    <h1>Achtergrondtaken</h1>
    <p class="intro">{{ __('operations.generated.t_9c33349ac53589cc') }}</p>

    @include('operations.runs._panel', ['runs' => $runs])

    <div style="margin-top: 1rem;">
        {{ $runs->links() }}
    </div>
@endsection
