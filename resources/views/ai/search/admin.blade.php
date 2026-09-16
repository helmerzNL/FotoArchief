@extends('layouts.app')

@section('title', 'AI semantisch zoeken')

@section('content')
    <h1>AI semantisch zoeken</h1>
    <p>Zoekt met een tekstembedding in hetzelfde model_space als de beeldindex. Resultaten blijven beperkt tot assets die u mag zien.</p>

    @include('operations._nav')

    @if($errors->any())
        <div class="card" role="alert">
            <strong>Zoeken mislukt.</strong>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="GET" action="{{ route('admin.operations.ai.search') }}" class="card">
        <label>Zoekvraag
            <input type="search" name="q" value="{{ $query }}" placeholder="bijvoorbeeld: groepsfoto op dorpsplein">
        </label>
        <label>Provider
            <select name="provider">
                <option value="local" @selected($provider === 'local')>Lokale/eigen provider</option>
                <option value="external" @selected($provider === 'external')>Externe provider</option>
            </select>
        </label>
        <button type="submit">Zoeken</button>
    </form>

    <section class="card">
        <h2>Resultaten</h2>
        @forelse($results as $result)
            <article>
                <h3><a href="{{ route('admin.assets.show', $result['asset_id']) }}">{{ $result['accession_number'] }}</a></h3>
                <p>{{ $result['title'] }}</p>
                <p>Score {{ number_format($result['score'], 3) }} · {{ $result['model_space'] }}</p>
            </article>
        @empty
            <p>Geen semantische resultaten.</p>
        @endforelse
    </section>
@endsection
