@extends('layouts.app')
@section('title', $source->name.' — FotoArchief')
@section('content')
<div class="card">
    <div class="eyebrow"><a href="{{ route('catalogue.sources.index') }}">&larr; Herkomstbronnen</a></div>
    <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1>{{ $source->name }}</h1>
            <span style="font-size: .85rem; padding: .2rem .5rem; background: var(--notice); border-radius: .25rem; text-transform: uppercase; font-weight: bold;">
                {{ ucfirst($source->source_type) }}
            </span>
            @if($source->reference_code)
                <span style="color: var(--muted); margin-left: .5rem;">Ref: <code>{{ $source->reference_code }}</code></span>
            @endif
            @if($source->acquisition_date)
                <span style="color: var(--muted); margin-left: .5rem;">Verworven: {{ $source->acquisition_date->format('d-m-Y') }}</span>
            @endif
        </div>
        <div class="actions">
            <a href="{{ route('catalogue.sources.edit', $source) }}" class="button secondary">Bewerken</a>
        </div>
    </div>

    @if($source->custody_history)
        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border);">
            <strong>Bewaargeschiedenis (Custody):</strong>
            <p>{{ $source->custody_history }}</p>
        </div>
    @endif

    @if($source->description)
        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border);">
            <strong>Beschrijving:</strong>
            <p>{{ $source->description }}</p>
        </div>
    @endif
</div>

<div class="card">
    <h2>Gekoppelde foto’s ({{ $assets->count() }})</h2>

    @if($assets->isEmpty())
        <p style="color: var(--muted);">Er zijn nog geen foto’s aan deze herkomstbron gekoppeld.</p>
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
                            <form method="post" action="{{ route('catalogue.sources.assets.remove', [$source, $asset]) }}?relationship_type={{ $asset->pivot->relationship_type }}" onsubmit="return confirm('Koppeling verwijderen?');">
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
        <h3>Foto koppelen aan deze herkomstbron</h3>
        <form method="post" action="{{ route('catalogue.sources.assets.add', $source) }}" style="margin-top: 1rem;">
            @csrf
            <div class="grid">
                <div>
                    <label for="accession_number">Aanwinstnummer of Foto-ID *</label>
                    <input type="text" id="accession_number" name="accession_number" placeholder="bijv. FA-01J..." required>
                </div>
                <div>
                    <label for="relationship_type">Type herkomst / relatie *</label>
                    <select id="relationship_type" name="relationship_type" required>
                        <option value="provenance">Provenance (historische herkomstlijn)</option>
                        <option value="donor">Schenking / Overdracht</option>
                        <option value="custody">Bewaarder / Archiefbewaring</option>
                        <option value="acquisition">Aankoop / Verwerving</option>
                        <option value="deposit">Bruikleen / Deposito</option>
                        <option value="other">Overig</option>
                    </select>
                </div>
            </div>

            <div class="grid">
                <div>
                    <label for="confidence">Zekerheid (0.00 - 1.00)</label>
                    <select id="confidence" name="confidence">
                        <option value="1.00">1.00 — Zeker / Gedocumenteerd</option>
                        <option value="0.80">0.80 — Zeer waarschijnlijk</option>
                        <option value="0.50">0.50 — Vermoedelijk</option>
                        <option value="0.25">0.25 — Onzeker</option>
                        <option value="">Niet gespecificeerd</option>
                    </select>
                </div>
                <div>
                    <label for="verification_status">Verificatiestatus *</label>
                    <select id="verification_status" name="verification_status" required>
                        <option value="unverified">Ongeverifieerd</option>
                        <option value="verified">Geverifieerd</option>
                        <option value="disputed">Betwist</option>
                    </select>
                </div>
            </div>

            <label for="note">Toelichting / Referentienotitie</label>
            <input type="text" id="note" name="note" placeholder="bijv. Schenkingsakte 1992 artikel 4">

            <button type="submit">Foto koppelen</button>
        </form>
    </div>
</div>
@endsection
