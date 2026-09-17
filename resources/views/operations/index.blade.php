@extends('layouts.app')

@section('title', 'Archiefbewerkingen')

@section('content')
    <h1>Archiefbewerkingen</h1>
    <p>{{ __('operations.generated.t_08230f595b9f9b33') }}</p>

    @include('operations._nav')

    <section class="card">
        <h2>{{ __('operations.generated.t_57b26f887faaa005') }}</h2>
        <dl>
            <dt>Duplicaten</dt>
            <dd>{{ __('operations.generated.t_50893c6bcc9a08c4') }}</dd>
            <dt>Bestandsversies</dt>
            <dd>{{ __('operations.generated.t_c950ec97a6c64744') }}</dd>
            <dt>Verwerking</dt>
            <dd>{{ __('operations.generated.t_9558583f88a37019') }}</dd>
            <dt>Integriteit</dt>
            <dd>{{ __('operations.generated.t_68f20939229ae2fb') }}</dd>
            <dt>Opslagmigratie</dt>
            <dd>{{ __('operations.generated.t_b61a008ae277a964') }}</dd>
            <dt>Prullenbak</dt>
            <dd>{{ __('operations.generated.t_368197c395df898a') }}</dd>
            <dt>OCR-tekst</dt>
            <dd>{{ __('operations.generated.t_3bfd83b9e5c9bc53') }}</dd>
            <dt>AI-instellingen</dt>
            <dd>{{ __('operations.generated.t_1eedecb824d9611c') }}</dd>
            <dt>AI-suggesties</dt>
            <dd>{{ __('operations.generated.t_7b143af60f217a04') }}</dd>
            <dt>{{ __('operations.generated.t_939ebed93c98ca85') }}</dt>
            <dd>{{ __('operations.generated.t_66944095a45b9a0a') }}</dd>
            <dt>Achtergrondtaken</dt>
            <dd>{{ __('operations.generated.t_368398c0b1b204a4') }}</dd>
        </dl>
    </section>
@endsection
