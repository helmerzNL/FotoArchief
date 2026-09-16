@extends('layouts.app')
@section('title', $location->name.' — FotoArchief')
@section('content')
<div class="card">
    <div class="eyebrow">
        <a href="{{ route('catalogue.locations.index') }}">Locaties</a>
        @if($location->parent)
            &rsaquo; <a href="{{ route('catalogue.locations.show', $location->parent) }}">{{ $location->parent->name }}</a>
        @endif
    </div>
    <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1>{{ $location->name }}</h1>
            <span style="font-size: .85rem; padding: .2rem .5rem; background: var(--notice); border-radius: .25rem; text-transform: uppercase; font-weight: bold;">
                {{ ucfirst($location->location_type) }}
            </span>
            @if($location->historical_period)
                <span style="color: var(--muted); margin-left: .5rem;">Periode: {{ $location->historical_period }}</span>
            @endif
        </div>
        <div class="actions">
            <a href="{{ route('catalogue.locations.edit', $location) }}" class="button secondary">Bewerken</a>
            <a href="{{ route('catalogue.locations.create', ['parent_id' => $location->id]) }}" class="button secondary">+ Sublocatie toevoegen</a>
        </div>
    </div>

    <p style="font-size: .95rem; color: var(--muted); margin-top: .5rem;">
        <strong>Volledig pad:</strong> {{ $location->fullPath() }}
    </p>

    @if($location->latitude !== null && $location->longitude !== null)
        <p style="font-size: .9rem; color: var(--muted);">
            <strong>Coördinaten:</strong> {{ $location->latitude }}, {{ $location->longitude }}
        </p>
    @endif

    @if($location->aliases->isNotEmpty())
        <p style="margin-top: 1rem;">
            <strong>Historische aliassen / Oude benamingen:</strong>
            {{ $location->aliases->pluck('name')->implode(', ') }}
        </p>
    @endif

    @if($location->description)
        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border);">
            <strong>Beschrijving / Historie:</strong>
            <p>{{ $location->description }}</p>
        </div>
    @endif
</div>

@if($location->children->isNotEmpty())
<div class="card">
    <h2>Onderliggende locaties ({{ $location->children->count() }})</h2>
    <table style="width: 100%; border-collapse: collapse; margin-top: .5rem;">
        <thead>
            <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                <th style="padding: .5rem;">Naam</th>
                <th style="padding: .5rem;">Type</th>
                <th style="padding: .5rem; text-align: right;">Gekoppelde foto’s</th>
                <th style="padding: .5rem; text-align: right;">Acties</th>
            </tr>
        </thead>
        <tbody>
            @foreach($location->children as $child)
                <tr style="border-bottom: 1px solid var(--border);">
                    <td style="padding: .5rem;">
                        <strong><a href="{{ route('catalogue.locations.show', $child) }}">{{ $child->name }}</a></strong>
                    </td>
                    <td style="padding: .5rem;">{{ ucfirst($child->location_type) }}</td>
                    <td style="padding: .5rem; text-align: right;">{{ $child->assets_count }}</td>
                    <td style="padding: .5rem; text-align: right;">
                        <a href="{{ route('catalogue.locations.show', $child) }}">Bekijken</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

<div class="card">
    <h2>Gekoppelde foto’s ({{ $assets->count() }})</h2>

    @if($assets->isEmpty())
        <p style="color: var(--muted);">Er zijn nog geen foto’s aan deze locatie gekoppeld.</p>
    @else
        <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                    <th style="padding: .5rem;">Foto</th>
                    <th style="padding: .5rem;">Relatie</th>
                    <th style="padding: .5rem;">Zekerheid</th>
                    <th style="padding: .5rem;">Status</th>
                    <th style="padding: .5rem;">Notitie</th>
                    <th style="padding: .5rem; text-align: right;">Acties</th>
                </tr>
            </thead>
            <tbody>
                @foreach($assets as $asset)
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td style="padding: .5rem;">
                            <strong><a href="{{ route('admin.assets.show', $asset) }}">{{ $asset->title ?: 'Geen titel' }}</a></strong>
                            <div style="font-size: .85rem; color: var(--muted);"><code>{{ $asset->accession_number }}</code></div>
                        </td>
                        <td style="padding: .5rem;">
                            <span style="font-size: .85rem; padding: .2rem .4rem; background: var(--notice); border-radius: .25rem;">
                                {{ ucfirst(str_replace('_', ' ', $asset->pivot->relationship_type)) }}
                            </span>
                        </td>
                        <td style="padding: .5rem;">
                            @if($asset->pivot->confidence !== null)
                                {{ number_format((float) $asset->pivot->confidence * 100, 0) }}%
                            @else
                                <span style="color: var(--muted);">Onbekend</span>
                            @endif
                        </td>
                        <td style="padding: .5rem;">
                            @if($asset->pivot->verification_status === 'verified')
                                <span style="color: var(--accent); font-weight: bold;">Geverifieerd</span>
                            @elseif($asset->pivot->verification_status === 'disputed')
                                <span style="color: var(--error); font-weight: bold;">Betwist</span>
                            @else
                                <span style="color: var(--muted);">Ongeverifieerd</span>
                            @endif
                        </td>
                        <td style="padding: .5rem; color: var(--muted);">
                            {{ $asset->pivot->note ?? '—' }}
                        </td>
                        <td style="padding: .5rem; text-align: right;">
                            <form method="post" action="{{ route('catalogue.locations.assets.remove', [$location, $asset]) }}?relationship_type={{ $asset->pivot->relationship_type }}" onsubmit="return confirm('Koppeling verwijderen?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="secondary" style="margin: 0; padding: .2rem .5rem; font-size: .85rem; color: var(--error); border-color: var(--error);">Ontkoppelen</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
        <h3>Foto koppelen aan deze locatie</h3>
        <form method="post" action="{{ route('catalogue.locations.assets.add', $location) }}" style="margin-top: 1rem;">
            @csrf
            <div class="grid">
                <div>
                    <label for="accession_number">Aanwinstnummer of Foto-ID *</label>
                    <input type="text" id="accession_number" name="accession_number" placeholder="bijv. FA-01J..." required>
                </div>
                <div>
                    <label for="relationship_type">Relatie / Type *</label>
                    <select id="relationship_type" name="relationship_type" required>
                        <option value="depicted_place">Afgebeelde plaats (locatie zichtbaar op beeld)</option>
                        <option value="creation_place">Plaats van vervaardiging</option>
                        <option value="subject_location">Onderwerplocatie</option>
                        <option value="origin">Herkomstlocatie</option>
                        <option value="destination">Bestemmingslocatie</option>
                        <option value="other">Overig</option>
                    </select>
                </div>
            </div>

            <div class="grid">
                <div>
                    <label for="confidence">Zekerheid / Betrouwbaarheid (0.00 - 1.00)</label>
                    <select id="confidence" name="confidence">
                        <option value="1.00">1.00 — Zeker / Vastgesteld</option>
                        <option value="0.80">0.80 — Zeer waarschijnlijk</option>
                        <option value="0.50">0.50 — Mogelijk / Vermoedelijk</option>
                        <option value="0.25">0.25 — Onzeker / Hypothese</option>
                        <option value="">Niet gespecificeerd</option>
                    </select>
                </div>
                <div>
                    <label for="verification_status">Verificatiestatus *</label>
                    <select id="verification_status" name="verification_status" required>
                        <option value="unverified">Ongeverifieerd (nog te controleren)</option>
                        <option value="verified">Geverifieerd (door archivaris bevestigd)</option>
                        <option value="disputed">Betwist (twijfelachtig / tegenstrijdige bronnen)</option>
                    </select>
                </div>
            </div>

            <label for="note">Toelichting / Bewijsvoering</label>
            <input type="text" id="note" name="note" placeholder="bijv. Herkenbaar aan kerktoren op achtergrond">

            <button type="submit">Foto koppelen</button>
        </form>
    </div>
</div>
@endsection
