@extends('layouts.app')
@section('title', 'Locaties — FotoArchief')
@section('content')
<div class="card">
    <div class="eyebrow"><a href="{{ route('catalogue.index') }}">&larr; Catalogus</a></div>
    <h1>Locaties</h1>
    <p class="intro">Geografische hiërarchie, historische plaatsnamen, straten en monumenten.</p>
    <div class="actions">
        <a href="{{ route('catalogue.locations.create') }}" class="button">+ Nieuwe locatie</a>
    </div>
</div>

<div class="card">
    <form method="get" action="{{ route('catalogue.locations.index') }}" style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem;">
        <div style="flex: 2; min-width: 200px;">
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Zoek op locatie of historische naam...">
        </div>
        <div style="flex: 1; min-width: 150px;">
            <select name="location_type" onchange="this.form.submit()">
                <option value="">Alle niveaus</option>
                <option value="country" {{ request('location_type') === 'country' ? 'selected' : '' }}>Land</option>
                <option value="province" {{ request('location_type') === 'province' ? 'selected' : '' }}>Provincie</option>
                <option value="municipality" {{ request('location_type') === 'municipality' ? 'selected' : '' }}>Gemeente</option>
                <option value="city" {{ request('location_type') === 'city' ? 'selected' : '' }}>Plaats / Stad / Dorp</option>
                <option value="street" {{ request('location_type') === 'street' ? 'selected' : '' }}>Straat</option>
                <option value="building" {{ request('location_type') === 'building' ? 'selected' : '' }}>Gebouw / Monument</option>
            </select>
        </div>
        <div>
            <button type="submit" class="secondary" style="margin: 0;">Zoeken</button>
            @if(request('q') || request('location_type'))
                <a href="{{ route('catalogue.locations.index') }}" class="button secondary" style="margin: 0;">Wissen</a>
            @endif
        </div>
    </form>

    @if($locations->isEmpty())
        <p>Geen locaties gevonden.</p>
    @else
        <x-catalogue-table>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                    <th style="padding: .5rem;">Naam &amp; Pad</th>
                    <th style="padding: .5rem;">Type</th>
                    <th style="padding: .5rem; text-align: right;">Aliassen</th>
                    <th style="padding: .5rem; text-align: right;">Sublocaties</th>
                    <th style="padding: .5rem; text-align: right;">Gekoppelde foto’s</th>
                    <th style="padding: .5rem; text-align: right;">Acties</th>
                </tr>
            </thead>
            <tbody>
                @foreach($locations as $loc)
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td style="padding: .5rem;">
                            <strong><a href="{{ route('catalogue.locations.show', $loc) }}">{{ $loc->name }}</a></strong>
                            <div style="font-size: .85rem; color: var(--muted);">{{ $loc->fullPath() }}</div>
                        </td>
                        <td style="padding: .5rem;">
                            <span style="font-size: .85rem; padding: .2rem .5rem; background: var(--notice); border-radius: .25rem;">
                                {{ ucfirst($loc->location_type) }}
                            </span>
                        </td>
                        <td style="padding: .5rem; text-align: right;">{{ $loc->aliases_count }}</td>
                        <td style="padding: .5rem; text-align: right;">{{ $loc->children_count }}</td>
                        <td style="padding: .5rem; text-align: right;">{{ $loc->assets_count }}</td>
                        <td style="padding: .5rem; text-align: right;">
                            <a href="{{ route('catalogue.locations.edit', $loc) }}" style="margin-right: .5rem;">Bewerken</a>
                            <a href="{{ route('catalogue.locations.show', $loc) }}">Bekijken</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </x-catalogue-table>

        <div style="margin-top: 1rem;">
            {{ $locations->links() }}
        </div>
    @endif
</div>
@endsection
