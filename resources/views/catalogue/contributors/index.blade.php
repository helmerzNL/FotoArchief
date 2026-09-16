@extends('layouts.app')
@section('title', 'Schenkers & Bijdragers — FotoArchief')
@section('content')
<div class="card">
    <div class="eyebrow"><a href="{{ route('catalogue.index') }}">&larr; Catalogus</a></div>
    <h1>Schenkers &amp; Bijdragers</h1>
    <p class="intro">Overzicht van schenkers, particuliere fotografen, verzamelaars en contactpersonen.</p>
    <div class="actions">
        <a href="{{ route('catalogue.contributors.create') }}" class="button">+ Nieuwe schenker / bijdrager</a>
        <a href="{{ route('catalogue.sources.index') }}" class="button secondary">Herkomstbronnen &amp; Archieven</a>
    </div>
</div>

<div class="card">
    <form method="get" action="{{ route('catalogue.contributors.index') }}" style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem;">
        <div style="flex: 2; min-width: 200px;">
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Zoek op naam of e-mail...">
        </div>
        <div style="flex: 1; min-width: 150px;">
            <select name="contributor_type" onchange="this.form.submit()">
                <option value="">Alle types</option>
                <option value="donor" {{ request('contributor_type') === 'donor' ? 'selected' : '' }}>Schenker / Donateur</option>
                <option value="photographer" {{ request('contributor_type') === 'photographer' ? 'selected' : '' }}>Fotograaf</option>
                <option value="collector" {{ request('contributor_type') === 'collector' ? 'selected' : '' }}>Verzamelaar</option>
                <option value="individual" {{ request('contributor_type') === 'individual' ? 'selected' : '' }}>Individu</option>
                <option value="organisation" {{ request('contributor_type') === 'organisation' ? 'selected' : '' }}>Organisatie</option>
            </select>
        </div>
        <div>
            <button type="submit" class="secondary" style="margin: 0;">Zoeken</button>
            @if(request('q') || request('contributor_type'))
                <a href="{{ route('catalogue.contributors.index') }}" class="button secondary" style="margin: 0;">Wissen</a>
            @endif
        </div>
    </form>

    @if($contributors->isEmpty())
        <p>Geen schenkers of bijdragers gevonden.</p>
    @else
        <x-catalogue-table>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                    <th style="padding: .5rem;">Naam</th>
                    <th style="padding: .5rem;">Type</th>
                    <th style="padding: .5rem;">E-mail / Contact</th>
                    <th style="padding: .5rem; text-align: right;">Gekoppelde foto’s</th>
                    <th style="padding: .5rem; text-align: right;">Acties</th>
                </tr>
            </thead>
            <tbody>
                @foreach($contributors as $c)
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td style="padding: .5rem;">
                            <strong><a href="{{ route('catalogue.contributors.show', $c) }}">{{ $c->name }}</a></strong>
                        </td>
                        <td style="padding: .5rem;">
                            <span style="font-size: .85rem; padding: .2rem .5rem; background: var(--notice); border-radius: .25rem;">
                                {{ ucfirst($c->contributor_type) }}
                            </span>
                        </td>
                        <td style="padding: .5rem;">{{ $c->email ?: '—' }}</td>
                        <td style="padding: .5rem; text-align: right;">{{ $c->assets_count }}</td>
                        <td style="padding: .5rem; text-align: right;">
                            <a href="{{ route('catalogue.contributors.edit', $c) }}" style="margin-right: .5rem;">Bewerken</a>
                            <a href="{{ route('catalogue.contributors.show', $c) }}">Bekijken</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </x-catalogue-table>

        <div style="margin-top: 1rem;">
            {{ $contributors->links() }}
        </div>
    @endif
</div>
@endsection
