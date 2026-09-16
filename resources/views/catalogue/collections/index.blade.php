@extends('layouts.app')
@section('title', 'Collecties & Albums — FotoArchief')
@section('content')
<div class="card">
    <div class="eyebrow"><a href="{{ route('catalogue.index') }}">&larr; Catalogus</a></div>
    <h1>Collecties &amp; Albums</h1>
    <p class="intro">Overzicht van alle thematische verzamelingen, fysieke albums en series in het archief.</p>
    <div class="actions">
        <a href="{{ route('catalogue.collections.create') }}" class="button">+ Nieuwe collectie / album</a>
    </div>
</div>

<div class="card">
    <h2>Collectieoverzicht</h2>
    @if($collections->isEmpty())
        <p>Er zijn nog geen collecties of albums aangemaakt.</p>
    @else
        <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                    <th style="padding: .5rem;">Titel</th>
                    <th style="padding: .5rem;">Type</th>
                    <th style="padding: .5rem;">Hoofdcollectie</th>
                    <th style="padding: .5rem; text-align: right;">Subcollecties</th>
                    <th style="padding: .5rem; text-align: right;">Foto’s</th>
                    <th style="padding: .5rem; text-align: right;">Acties</th>
                </tr>
            </thead>
            <tbody>
                @foreach($collections as $col)
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td style="padding: .5rem;">
                            <strong><a href="{{ route('catalogue.collections.show', $col) }}">{{ $col->title }}</a></strong>
                            <div style="font-size: .85rem; color: var(--muted);">/{{ $col->slug }}</div>
                        </td>
                        <td style="padding: .5rem;">
                            <span style="font-size: .85rem; padding: .2rem .5rem; background: var(--notice); border-radius: .25rem;">
                                {{ ucfirst($col->collection_type) }}
                            </span>
                        </td>
                        <td style="padding: .5rem;">
                            @if($col->parent)
                                <a href="{{ route('catalogue.collections.show', $col->parent) }}">{{ $col->parent->title }}</a>
                            @else
                                <span style="color: var(--muted);">&mdash;</span>
                            @endif
                        </td>
                        <td style="padding: .5rem; text-align: right;">{{ $col->children_count }}</td>
                        <td style="padding: .5rem; text-align: right;">{{ $col->assets_count }}</td>
                        <td style="padding: .5rem; text-align: right;">
                            <a href="{{ route('catalogue.collections.edit', $col) }}" style="margin-right: .5rem;">Bewerken</a>
                            <a href="{{ route('catalogue.collections.show', $col) }}">Bekijken</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
