@extends('layouts.app')
@section('title', $location->name.' — Vistora')
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
            <a href="{{ route('catalogue.locations.create', ['parent_id' => $location->id]) }}" class="button secondary">{{ __('catalogue.generated.t_5e525070c9243cc3') }}</a>
        </div>
    </div>

    <p style="font-size: .95rem; color: var(--muted); margin-top: .5rem;">
        <strong>{{ __('catalogue.generated.t_c34880ff55667eb9') }}</strong> {{ $location->fullPath() }}
    </p>

    @if($location->latitude !== null && $location->longitude !== null)
        <p style="font-size: .9rem; color: var(--muted);">
            <strong>{{ __('catalogue.generated.t_7f822205ba3fd26c') }}</strong> {{ $location->latitude }}, {{ $location->longitude }}
        </p>
    @endif

    @if($location->aliases->isNotEmpty())
        <p style="margin-top: 1rem;">
            <strong>{{ __('catalogue.generated.t_b8654b79d7782d7d') }}</strong>
            {{ $location->aliases->pluck('name')->implode(', ') }}
        </p>
    @endif

    @if($location->description)
        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border);">
            <strong>{{ __('catalogue.generated.t_1cb238eb296ce4be') }}</strong>
            <p>{{ $location->description }}</p>
        </div>
    @endif
</div>

@if($location->children->isNotEmpty())
<div class="card">
    <h2>{{ __('catalogue.generated.t_f7a0f43f79ab990f') }}{{ $location->children->count() }})</h2>
    <x-catalogue-table>
    <table style="width: 100%; border-collapse: collapse; margin-top: .5rem;">
        <thead>
            <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                <th style="padding: .5rem;">Naam</th>
                <th style="padding: .5rem;">Type</th>
                <th style="padding: .5rem; text-align: right;">{{ __('catalogue.generated.t_9d945313bfdd8bfa') }}</th>
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
    </x-catalogue-table>
</div>
@endif

<div class="card">
    <h2>{{ __('catalogue.generated.t_9084c0e65cc71fa7') }}{{ $assets->count() }})</h2>

    @if($assets->isEmpty())
        <p style="color: var(--muted);">{{ __('catalogue.generated.t_9dff2cb4cc4e5347') }}</p>
    @else
        <x-catalogue-table>
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
        </x-catalogue-table>
    @endif

    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
        <h3>{{ __('catalogue.generated.t_eafd8e9e4256845a') }}</h3>
        <form method="post" action="{{ route('catalogue.locations.assets.add', $location) }}" style="margin-top: 1rem;">
            @csrf
            <div class="grid">
                <div>
                    <label for="accession_number">{{ __('catalogue.generated.t_defde4bbc85eec4e') }}</label>
                    <input type="text" id="accession_number" name="accession_number" placeholder="{{ __('catalogue.generated.t_1af3ddcb66fd405d') }}" required>
                </div>
                <div>
                    <label for="relationship_type">{{ __('catalogue.generated.t_9a1619d2fc2f81f0') }}</label>
                    <select id="relationship_type" name="relationship_type" required>
                        <option value="depicted_place">{{ __('catalogue.generated.t_23c801929c685add') }}</option>
                        <option value="creation_place">{{ __('catalogue.generated.t_63f6a394a1c0891f') }}</option>
                        <option value="subject_location">Onderwerplocatie</option>
                        <option value="origin">Herkomstlocatie</option>
                        <option value="destination">Bestemmingslocatie</option>
                        <option value="other">Overig</option>
                    </select>
                </div>
            </div>

            <div class="grid">
                <div>
                    <label for="confidence">{{ __('catalogue.generated.t_232ac7e11fe36f4b') }}</label>
                    <select id="confidence" name="confidence">
                        <option value="1.00">{{ __('catalogue.generated.t_7a4f0376e42557ce') }}</option>
                        <option value="0.80">{{ __('catalogue.generated.t_bac2bc017baa6381') }}</option>
                        <option value="0.50">{{ __('catalogue.generated.t_fabb52db92d99ab6') }}</option>
                        <option value="0.25">{{ __('catalogue.generated.t_b3bf9c634095f559') }}</option>
                        <option value="">{{ __('catalogue.generated.t_6c47b1d165bfdec6') }}</option>
                    </select>
                </div>
                <div>
                    <label for="verification_status">{{ __('catalogue.generated.t_5c0132b44624795c') }}</label>
                    <select id="verification_status" name="verification_status" required>
                        <option value="unverified">{{ __('catalogue.generated.t_d52aae8e3d26b3bc') }}</option>
                        <option value="verified">{{ __('catalogue.generated.t_5ea81f6f37b275b8') }}</option>
                        <option value="disputed">{{ __('catalogue.generated.t_63ce1de33fb42c7e') }}</option>
                    </select>
                </div>
            </div>

            <label for="note">{{ __('catalogue.generated.t_3e94a93f2bd1f575') }}</label>
            <input type="text" id="note" name="note" placeholder="{{ __('catalogue.generated.t_6004ad7a8c953e55') }}">

            <button type="submit">{{ __('catalogue.generated.t_c400ed2e32ef6b6d') }}</button>
        </form>
    </div>
</div>
@endsection
