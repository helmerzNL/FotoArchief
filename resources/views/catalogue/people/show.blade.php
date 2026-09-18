@extends('layouts.app')
@section('title', $person->display_name.' — Vistora')
@section('content')
<div class="card">
    <div class="eyebrow"><a href="{{ route('catalogue.people.index') }}">{{ __('catalogue.generated.t_656eada0ad92f23f') }}</a></div>
    <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1>{{ $person->display_name }}</h1>
            <span style="font-size: .85rem; padding: .2rem .5rem; background: var(--notice); border-radius: .25rem; text-transform: uppercase; font-weight: bold;">
                {{ $person->entity_type === 'organisation' ? 'Organisatie' : 'Persoon' }}
            </span>
            @if($person->sort_name && $person->sort_name !== $person->display_name)
                <span style="color: var(--muted); margin-left: .5rem;">({{ __('catalogue.fragments.sort_name') }}: {{ $person->sort_name }})</span>
            @endif
        </div>
        <div class="actions">
            <a href="{{ route('catalogue.people.edit', $person) }}" class="button secondary">Bewerken</a>
        </div>
    </div>

    @if($person->aliases->isNotEmpty())
        <p style="margin-top: 1rem;">
            <strong>{{ __('catalogue.generated.t_030fe9b1b7f288a8') }}</strong>
            {{ $person->aliases->pluck('name')->implode(', ') }}
        </p>
    @endif

    @if($person->biographical_note)
        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border);">
            <strong>{{ __('catalogue.generated.t_2c9c01c92166edd0') }}</strong>
            <p>{{ $person->biographical_note }}</p>
        </div>
    @endif
</div>

<div class="card">
    <h2>{{ __('catalogue.generated.t_9084c0e65cc71fa7') }}{{ $assets->count() }})</h2>

    @if($assets->isEmpty())
        <p style="color: var(--muted);">{{ __('catalogue.generated.t_c05fd0afc78e347c') }}</p>
    @else
        <x-catalogue-table>
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
        </x-catalogue-table>
    @endif

    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
        <h3>{{ __('catalogue.generated.t_df6e23c2cc8d1eda') }}</h3>
        <form method="post" action="{{ route('catalogue.people.assets.add', $person) }}" style="margin-top: 1rem;">
            @csrf
            <div class="grid">
                <div>
                    <label for="accession_number">{{ __('catalogue.generated.t_defde4bbc85eec4e') }}</label>
                    <input type="text" id="accession_number" name="accession_number" placeholder="{{ __('catalogue.generated.t_1af3ddcb66fd405d') }}" required>
                </div>
                <div>
                    <label for="relationship_type">{{ __('catalogue.generated.t_4d90cd591e4e2df8') }}</label>
                    <select id="relationship_type" name="relationship_type" required>
                        <option value="depicted">{{ __('catalogue.generated.t_5a449e7e64d3f6a3') }}</option>
                        <option value="photographer">{{ __('catalogue.generated.t_6cef7b451ac505db') }}</option>
                        <option value="subject">{{ __('catalogue.generated.t_ae724d40fb044217') }}</option>
                        <option value="mentioned">{{ __('catalogue.generated.t_ead43ec94828acf6') }}</option>
                        <option value="creator">{{ __('catalogue.generated.t_9992ee70635f952c') }}</option>
                        <option value="publisher">Uitgever</option>
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
            <input type="text" id="note" name="note" placeholder="{{ __('catalogue.generated.t_4563e2acbb9cff99') }}">

            <button type="submit">{{ __('catalogue.generated.t_c400ed2e32ef6b6d') }}</button>
        </form>
    </div>
</div>
@endsection
