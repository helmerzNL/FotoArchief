@extends('layouts.app')
@section('title', $collection->title.' — FotoArchief')
@section('content')
<div class="card">
    <div class="eyebrow">
        <a href="{{ route('catalogue.collections.index') }}">Collecties</a>
        @if($collection->parent)
            &rsaquo; <a href="{{ route('catalogue.collections.show', $collection->parent) }}">{{ $collection->parent->title }}</a>
        @endif
    </div>
    <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1>{{ $collection->title }}</h1>
            <span style="font-size: .85rem; padding: .2rem .5rem; background: var(--notice); border-radius: .25rem; text-transform: uppercase; font-weight: bold;">
                {{ ucfirst($collection->collection_type) }}
            </span>
            <span style="color: var(--muted); margin-left: .5rem;">/{{ $collection->slug }}</span>
        </div>
        <div class="actions">
            <a href="{{ route('catalogue.collections.edit', $collection) }}" class="button secondary">Bewerken</a>
            <a href="{{ route('catalogue.collections.create', ['parent_id' => $collection->id]) }}" class="button secondary">+ Subcollectie toevoegen</a>
        </div>
    </div>

    @if($collection->description)
        <p style="margin-top: 1rem;">{{ $collection->description }}</p>
    @endif
</div>

@if($collection->children->isNotEmpty())
<div class="card">
    <h2>Subcollecties &amp; Albums ({{ $collection->children->count() }})</h2>
    <table style="width: 100%; border-collapse: collapse; margin-top: .5rem;">
        <thead>
            <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                <th style="padding: .5rem;">Titel</th>
                <th style="padding: .5rem;">Type</th>
                <th style="padding: .5rem; text-align: right;">Foto’s</th>
                <th style="padding: .5rem; text-align: right;">Acties</th>
            </tr>
        </thead>
        <tbody>
            @foreach($collection->children as $child)
                <tr style="border-bottom: 1px solid var(--border);">
                    <td style="padding: .5rem;">
                        <strong><a href="{{ route('catalogue.collections.show', $child) }}">{{ $child->title }}</a></strong>
                    </td>
                    <td style="padding: .5rem;">{{ ucfirst($child->collection_type) }}</td>
                    <td style="padding: .5rem; text-align: right;">{{ $child->assets_count }}</td>
                    <td style="padding: .5rem; text-align: right;">
                        <a href="{{ route('catalogue.collections.show', $child) }}">Bekijken</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <h2>Foto’s in deze collectie ({{ $assets->count() }})</h2>
    </div>

    @if($assets->isEmpty())
        <p style="color: var(--muted); margin-top: .5rem;">Er zijn nog geen foto’s aan deze collectie gekoppeld.</p>
    @else
        <form method="post" action="{{ route('catalogue.collections.reorder', $collection) }}" style="margin-top: 1rem;">
            @csrf
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                        <th style="padding: .5rem; width: 80px;">Positie</th>
                        <th style="padding: .5rem;">Foto</th>
                        <th style="padding: .5rem;">Nummer</th>
                        <th style="padding: .5rem;">Notitie</th>
                        <th style="padding: .5rem; text-align: right;">Acties</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($assets as $asset)
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding: .5rem;">
                                <input type="hidden" name="ordered_asset_ids[]" value="{{ $asset->id }}">
                                <span style="font-weight: bold;">#{{ $asset->pivot->position ?? ($loop->index + 1) }}</span>
                            </td>
                            <td style="padding: .5rem;">
                                <strong><a href="{{ route('admin.assets.show', $asset) }}">{{ $asset->title ?: 'Geen titel' }}</a></strong>
                            </td>
                            <td style="padding: .5rem;"><code>{{ $asset->accession_number }}</code></td>
                            <td style="padding: .5rem; color: var(--muted);">{{ $asset->pivot->note ?? '—' }}</td>
                            <td style="padding: .5rem; text-align: right;">
                                <div style="display: inline-flex; gap: .5rem; justify-content: flex-end; align-items: center;">
                                    {{-- Move asset to another collection form --}}
                                    <form method="post" action="{{ route('catalogue.collections.assets.move', [$collection, $asset]) }}" style="display: inline-flex; gap: .25rem; align-items: center;">
                                        @csrf
                                        <select name="target_collection_id" style="width: auto; padding: .2rem .4rem; font-size: .85rem;" required>
                                            <option value="">Verplaats naar...</option>
                                            @foreach($allOtherCollections as $otherCol)
                                                <option value="{{ $otherCol->id }}">{{ $otherCol->title }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="secondary" style="margin: 0; padding: .2rem .5rem; font-size: .85rem;">Verplaats</button>
                                    </form>

                                    {{-- Remove asset from collection --}}
                                    <form method="post" action="{{ route('catalogue.collections.assets.remove', [$collection, $asset]) }}" style="display: inline;" onsubmit="return confirm('Foto loskoppelen van deze collectie?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="secondary" style="margin: 0; padding: .2rem .5rem; font-size: .85rem; color: var(--error); border-color: var(--error);">Loskoppelen</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </form>
    @endif

    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
        <h3>Foto toevoegen aan deze collectie</h3>
        <form method="post" action="{{ route('catalogue.collections.assets.add', $collection) }}" style="margin-top: 1rem;">
            @csrf
            <div class="grid">
                <div>
                    <label for="accession_number">Aanwinstnummer of Foto-ID *</label>
                    <input type="text" id="accession_number" name="accession_number" placeholder="bijv. FA-01J..." required>
                </div>
                <div>
                    <label for="asset_position">Positie in collectie</label>
                    <input type="number" id="asset_position" name="position" min="1" placeholder="Automatisch achteraan">
                </div>
            </div>
            <label for="asset_note">Notitie / bijschrift in context van collectie</label>
            <input type="text" id="asset_note" name="note" placeholder="bijv. Pagina 1, openingsfoto">

            <button type="submit">Foto toevoegen</button>
        </form>
    </div>
</div>
@endsection
