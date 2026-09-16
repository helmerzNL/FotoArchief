@extends('layouts.app')
@section('title', 'Personen & Organisaties — FotoArchief')
@section('content')
<div class="card">
    <div class="eyebrow"><a href="{{ route('catalogue.index') }}">&larr; Catalogus</a></div>
    <h1>Personen &amp; Organisaties</h1>
    <p class="intro">Beheer herbruikbare identiteiten, biografische gegevens, historische aliassen en rollen bij foto’s.</p>
    <div class="actions">
        <a href="{{ route('catalogue.people.create') }}" class="button">+ Nieuwe persoon / organisatie</a>
    </div>
</div>

<div class="card">
    <form method="get" action="{{ route('catalogue.people.index') }}" style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem;">
        <div style="flex: 2; min-width: 200px;">
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Zoek op naam of alias...">
        </div>
        <div style="flex: 1; min-width: 150px;">
            <select name="entity_type" onchange="this.form.submit()">
                <option value="">Alle types</option>
                <option value="person" {{ request('entity_type') === 'person' ? 'selected' : '' }}>Enkel personen</option>
                <option value="organisation" {{ request('entity_type') === 'organisation' ? 'selected' : '' }}>Enkel organisaties</option>
            </select>
        </div>
        <div>
            <button type="submit" class="secondary" style="margin: 0;">Zoeken</button>
            @if(request('q') || request('entity_type'))
                <a href="{{ route('catalogue.people.index') }}" class="button secondary" style="margin: 0;">Wissen</a>
            @endif
        </div>
    </form>

    @if($people->isEmpty())
        <p>Geen personen of organisaties gevonden.</p>
    @else
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                    <th style="padding: .5rem;">Naam</th>
                    <th style="padding: .5rem;">Type</th>
                    <th style="padding: .5rem; text-align: right;">Aliassen</th>
                    <th style="padding: .5rem; text-align: right;">Gekoppelde foto’s</th>
                    <th style="padding: .5rem; text-align: right;">Acties</th>
                </tr>
            </thead>
            <tbody>
                @foreach($people as $p)
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td style="padding: .5rem;">
                            <strong><a href="{{ route('catalogue.people.show', $p) }}">{{ $p->display_name }}</a></strong>
                            @if($p->sort_name && $p->sort_name !== $p->display_name)
                                <span style="font-size: .85rem; color: var(--muted);">({{ $p->sort_name }})</span>
                            @endif
                        </td>
                        <td style="padding: .5rem;">
                            <span style="font-size: .85rem; padding: .2rem .5rem; background: var(--notice); border-radius: .25rem;">
                                {{ $p->entity_type === 'organisation' ? 'Organisatie' : 'Persoon' }}
                            </span>
                        </td>
                        <td style="padding: .5rem; text-align: right;">{{ $p->aliases_count }}</td>
                        <td style="padding: .5rem; text-align: right;">{{ $p->assets_count }}</td>
                        <td style="padding: .5rem; text-align: right;">
                            <a href="{{ route('catalogue.people.edit', $p) }}" style="margin-right: .5rem;">Bewerken</a>
                            <a href="{{ route('catalogue.people.show', $p) }}">Bekijken</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div style="margin-top: 1rem;">
            {{ $people->links() }}
        </div>
    @endif
</div>
@endsection
