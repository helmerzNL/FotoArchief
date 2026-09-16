@extends('layouts.app')

@section('title', 'AI-suggesties beoordelen')

@section('content')
    <h1>AI-suggesties beoordelen</h1>
    <p>Suggesties wijzigen niets totdat een bevoegde gebruiker ze accepteert. Controleer bron, context en lock-versie.</p>

    @include('operations._nav')

    @if(session('status'))
        <div class="card" role="status">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="card" role="alert">
            <strong>Beoordeling mislukt.</strong>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="card">
        <h2>Open suggesties</h2>
        @forelse($suggestions as $suggestion)
            <article style="border-block-end:1px solid var(--border);padding:1rem 0;">
                <h3>{{ $suggestion->asset?->accession_number }} · {{ $suggestion->suggestion_type }}</h3>
                <p>{{ $suggestion->value }}</p>
                <p>Bronversie {{ $suggestion->source_asset_lock_version }} · checksum {{ $suggestion->source_file_sha256 }}</p>
                <form method="POST" action="{{ route('admin.operations.ai.suggestions.accept', $suggestion) }}" style="display:inline-block;margin-inline-end:1rem;">
                    @csrf
                    <input type="hidden" name="lock_version" value="{{ $suggestion->asset?->lock_version }}">
                    <button type="submit">Accepteren</button>
                </form>
                <form method="POST" action="{{ route('admin.operations.ai.suggestions.reject', $suggestion) }}" style="display:inline-block;">
                    @csrf
                    <input type="text" name="review_note" placeholder="Optionele afwijsreden" maxlength="500">
                    <button type="submit">Afwijzen</button>
                </form>
            </article>
        @empty
            <p>Er staan geen AI-suggesties klaar voor beoordeling.</p>
        @endforelse

        {{ $suggestions->links() }}
    </section>
@endsection
