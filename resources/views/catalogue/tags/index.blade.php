@extends('layouts.app')
@section('title', 'Tags & Trefwoorden - FotoArchief')
@section('content')
<p class="eyebrow"><a href="{{ route('catalogue.index') }}">← Catalogus Dashboard</a></p>
<h1>Tags &amp; Trefwoorden</h1>

@if(session('status'))
    <div class="card" style="border-color: #16a34a; background-color: #f0fdf4;">
        <p>{{ session('status') }}</p>
    </div>
@endif

<section class="card">
    <div class="actions" style="margin-bottom: 1rem;">
        <a href="{{ route('catalogue.tags.create') }}" class="button">Nieuwe tag toevoegen</a>
    </div>

    <form method="get" action="{{ route('catalogue.tags.index') }}" style="margin-bottom: 1.5rem;">
        <div class="grid">
            <div>
                <label for="q">Zoeken op tag of synoniem</label>
                <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="bijv. kerk, markt, monument...">
            </div>
        </div>
        <div class="actions">
            <button type="submit">Zoeken</button>
            @if(request('q'))
                <a href="{{ route('catalogue.tags.index') }}" class="button secondary">Wissen</a>
            @endif
        </div>
    </form>

    <ul class="asset-list">
        @forelse($tags as $tag)
            <li>
                <a href="{{ route('catalogue.tags.show', $tag) }}"><strong>{{ $tag->name }}</strong></a>
                <small>
                    {{ $tag->assets_count }} {{ $tag->assets_count === 1 ? 'foto' : 'foto’s' }}
                    @if($tag->synonyms->isNotEmpty())
                        · Synoniemen: {{ $tag->synonyms->pluck('name')->join(', ') }}
                    @endif
                    @if($tag->description)
                        · {{ Str::limit($tag->description, 60) }}
                    @endif
                </small>
            </li>
        @empty
            <li>Geen tags gevonden.</li>
        @endforelse
    </ul>
</section>
@endsection
