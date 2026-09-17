@extends('layouts.app')
@section('title', $contributor->name.' — FotoArchief')
@section('content')
<div class="card">
    <div class="eyebrow"><a href="{{ route('catalogue.contributors.index') }}">{{ __('catalogue.generated.t_782eafaa79144033') }}</a></div>
    <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1>{{ $contributor->name }}</h1>
            <span style="font-size: .85rem; padding: .2rem .5rem; background: var(--notice); border-radius: .25rem; text-transform: uppercase; font-weight: bold;">
                {{ ucfirst($contributor->contributor_type) }}
            </span>
            @if($contributor->email)
                <span style="color: var(--muted); margin-left: .5rem;">{{ $contributor->email }}</span>
            @endif
        </div>
        <div class="actions">
            <a href="{{ route('catalogue.contributors.edit', $contributor) }}" class="button secondary">Bewerken</a>
        </div>
    </div>

    @if($contributor->contact_details)
        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border);">
            <strong>{{ __('catalogue.generated.t_1c819fc0cb340373') }}</strong>
            <p>{{ $contributor->contact_details }}</p>
        </div>
    @endif

    @if($contributor->note)
        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border);">
            <strong>Notitie:</strong>
            <p>{{ $contributor->note }}</p>
        </div>
    @endif
</div>

<div class="card">
    <h2>{{ __('catalogue.generated.t_9084c0e65cc71fa7') }}{{ $assets->count() }})</h2>

    @if($assets->isEmpty())
        <p style="color: var(--muted);">{{ __('catalogue.generated.t_405b49b9a358a342') }}</p>
    @else
        <x-catalogue-table>
        <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                    <th style="padding: .5rem;">Foto</th>
                    <th style="padding: .5rem;">{{ __('catalogue.generated.t_2c31fcb54b4ded22') }}</th>
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
                            <form method="post" action="{{ route('catalogue.contributors.assets.remove', [$contributor, $asset]) }}?relationship_type={{ $asset->pivot->relationship_type }}" onsubmit="return confirm('Koppeling verwijderen?');">
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
        <h3>{{ __('catalogue.generated.t_6a1880cc8652efaa') }}</h3>
        <form method="post" action="{{ route('catalogue.contributors.assets.add', $contributor) }}" style="margin-top: 1rem;">
            @csrf
            <div class="grid">
                <div>
                    <label for="accession_number">{{ __('catalogue.generated.t_defde4bbc85eec4e') }}</label>
                    <input type="text" id="accession_number" name="accession_number" placeholder="{{ __('catalogue.generated.t_1af3ddcb66fd405d') }}" required>
                </div>
                <div>
                    <label for="relationship_type">{{ __('catalogue.generated.t_51c5b60859105d79') }}</label>
                    <select id="relationship_type" name="relationship_type" required>
                        <option value="donor">{{ __('catalogue.generated.t_6b7c5cff25c942d4') }}</option>
                        <option value="photographer">Fotograaf</option>
                        <option value="creator">{{ __('catalogue.generated.t_56fcf69d99538324') }}</option>
                        <option value="collector">Verzamelaar</option>
                        <option value="contact">Contactpersoon</option>
                        <option value="other">Overig</option>
                    </select>
                </div>
            </div>

            <div class="grid">
                <div>
                    <label for="confidence">{{ __('catalogue.generated.t_b5bcb118555e9f9e') }}</label>
                    <select id="confidence" name="confidence">
                        <option value="1.00">{{ __('catalogue.generated.t_687d40be67ac729f') }}</option>
                        <option value="0.80">{{ __('catalogue.generated.t_bac2bc017baa6381') }}</option>
                        <option value="0.50">{{ __('catalogue.generated.t_3ba2cd0c2f9bd138') }}</option>
                        <option value="0.25">{{ __('catalogue.generated.t_84457da434a0a58e') }}</option>
                        <option value="">{{ __('catalogue.generated.t_6c47b1d165bfdec6') }}</option>
                    </select>
                </div>
                <div>
                    <label for="verification_status">{{ __('catalogue.generated.t_5c0132b44624795c') }}</label>
                    <select id="verification_status" name="verification_status" required>
                        <option value="unverified">Ongeverifieerd</option>
                        <option value="verified">Geverifieerd</option>
                        <option value="disputed">Betwist</option>
                    </select>
                </div>
            </div>

            <label for="note">Toelichting</label>
            <input type="text" id="note" name="note" placeholder="{{ __('catalogue.generated.t_f3063e8c397cfdff') }}">

            <button type="submit">{{ __('catalogue.generated.t_c400ed2e32ef6b6d') }}</button>
        </form>
    </div>
</div>
@endsection
