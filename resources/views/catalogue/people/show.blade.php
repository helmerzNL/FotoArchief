@extends('layouts.app')
@section('title', $person->display_name.' — FotoArchief')
@section('content')
<div class="card">
    <div class="eyebrow"><a href="{{ route('catalogue.people.index') }}">&larr; Personen &amp; Organisaties</a></div>
    <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1>{{ $person->display_name }}</h1>
            <span style="font-size: .85rem; padding: .2rem .5rem; background: var(--notice); border-radius: .25rem; text-transform: uppercase; font-weight: bold;">
                {{ $person->entity_type === 'organisation' ? 'Organisatie' : 'Persoon' }}
            </span>
            @if($person->sort_name && $person->sort_name !== $person->display_name)
                <span style="color: var(--muted); margin-left: .5rem;">(Sorteernaam: {{ $person->sort_name }})</span>
            @endif
        </div>
        <div class="actions">
            <a href="{{ route('catalogue.people.edit', $person) }}" class="button secondary">Bewerken</a>
        </div>
    </div>

    @if($person->aliases->isNotEmpty())
        <p style="margin-top: 1rem;">
            <strong>Aliassen / Alternatieve namen:</strong>
            {{ $person->aliases->pluck('name')->implode(', ') }}
        </p>
    @endif

    @if($person->biographical_note)
        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border);">
            <strong>Biografie / Toelichting:</strong>
            <p>{{ $person->biographical_note }}</p>
        </div>
    @endif
</div>

<div class="card">
    <h2>Gekoppelde foto’s ({{ $assets->count() }})</h2>

    @if($assets->isEmpty())
        <p style="color: var(--muted);">Er zijn nog geen foto’s aan deze persoon/organisatie gekoppeld.</p>
    @else
        <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                    <th style="padding: .5rem;">Foto</th>
                    <th style="padding: .5rem;">Rol</th>
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
                                {{ ucfirst($asset->pivot->relationship_type) }}
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
                            <form method="post" action="{{ route('catalogue.people.assets.remove', [$person, $asset]) }}?relationship_type={{ $asset->pivot->relationship_type }}" onsubmit="return confirm('Koppeling verwijderen?');">
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
        <h3>Foto koppelen met rol en onzekerheid</h3>
        <form method="post" action="{{ route('catalogue.people.assets.add', $person) }}" style="margin-top: 1rem;">
            @csrf
            <div class="grid">
                <div>
                    <label for="accession_number">Aanwinstnummer of Foto-ID *</label>
                    <input type="text" id="accession_number" name="accession_number" placeholder="bijv. FA-01J..." required>
                </div>
                <div>
                    <label for="relationship_type">Rol / Relatie *</label>
                    <select id="relationship_type" name="relationship_type" required>
                        <option value="depicted">Afgebeeld (persoon zichtbaar op foto)</option>
                        <option value="photographer">Fotograaf / Vervaardiger</option>
                        <option value="subject">Onderwerp (hoofdthema)</option>
                        <option value="mentioned">Vermeld / Gerelateerd</option>
                        <option value="creator">Maker / Studio</option>
                        <option value="publisher">Uitgever</option>
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
            <input type="text" id="note" name="note" placeholder="bijv. Herkend door familiearchief, 3e persoon links">

            <button type="submit">Foto koppelen</button>
        </form>
    </div>
</div>
@endsection
