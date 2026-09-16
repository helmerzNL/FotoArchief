@extends('layouts.app')
@section('title', 'Herkomstbronnen & Archieven — FotoArchief')
@section('content')
<div class="card">
    <div class="eyebrow"><a href="{{ route('catalogue.index') }}">&larr; Catalogus</a></div>
    <h1>Herkomstbronnen &amp; Archieven</h1>
    <p class="intro">Provenance-registratie, institutionele herkomst, fysieke vindplaatsen en referentiecodes.</p>
    <div class="actions">
        <a href="{{ route('catalogue.sources.create') }}" class="button">+ Nieuwe herkomstbron</a>
        <a href="{{ route('catalogue.contributors.index') }}" class="button secondary">Schenkers &amp; Bijdragers bekijken</a>
    </div>
</div>

<div class="card">
    <form method="get" action="{{ route('catalogue.sources.index') }}" style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem;">
        <div style="flex: 2; min-width: 200px;">
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Zoek op naam of referentiecode...">
        </div>
        <div style="flex: 1; min-width: 150px;">
            <select name="source_type" onchange="this.form.submit()">
                <option value="">Alle types</option>
                <option value="archive" {{ request('source_type') === 'archive' ? 'selected' : '' }}>Archief / Instelling</option>
                <option value="donor" {{ request('source_type') === 'donor' ? 'selected' : '' }}>Schenking / Particulier</option>
                <option value="collection" {{ request('source_type') === 'collection' ? 'selected' : '' }}>Deelcollectie</option>
                <option value="family" {{ request('source_type') === 'family' ? 'selected' : '' }}>Familiearchief</option>
                <option value="other" {{ request('source_type') === 'other' ? 'selected' : '' }}>Overig</option>
            </select>
        </div>
        <div>
            <button type="submit" class="secondary" style="margin: 0;">Zoeken</button>
            @if(request('q') || request('source_type'))
                <a href="{{ route('catalogue.sources.index') }}" class="button secondary" style="margin: 0;">Wissen</a>
            @endif
        </div>
    </form>

    @if($sources->isEmpty())
        <p>Geen herkomstbronnen gevonden.</p>
    @else
        <x-catalogue-table>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                    <th style="padding: .5rem;">Naam</th>
                    <th style="padding: .5rem;">Type</th>
                    <th style="padding: .5rem;">Referentiecode</th>
                    <th style="padding: .5rem; text-align: right;">Gekoppelde foto’s</th>
                    <th style="padding: .5rem; text-align: right;">Acties</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sources as $s)
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td style="padding: .5rem;">
                            <strong><a href="{{ route('catalogue.sources.show', $s) }}">{{ $s->name }}</a></strong>
                        </td>
                        <td style="padding: .5rem;">
                            <span style="font-size: .85rem; padding: .2rem .5rem; background: var(--notice); border-radius: .25rem;">
                                {{ ucfirst($s->source_type) }}
                            </span>
                        </td>
                        <td style="padding: .5rem;"><code>{{ $s->reference_code ?: '—' }}</code></td>
                        <td style="padding: .5rem; text-align: right;">{{ $s->assets_count }}</td>
                        <td style="padding: .5rem; text-align: right;">
                            <a href="{{ route('catalogue.sources.edit', $s) }}" style="margin-right: .5rem;">Bewerken</a>
                            <a href="{{ route('catalogue.sources.show', $s) }}">Bekijken</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </x-catalogue-table>

        <div style="margin-top: 1rem;">
            {{ $sources->links() }}
        </div>
    @endif
</div>
@endsection
