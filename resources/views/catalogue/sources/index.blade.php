@extends('layouts.app')
@section('title', 'Herkomstbronnen & Archieven — Vistora')
@section('content')
<div class="card">
    <div class="eyebrow"><a href="{{ route('catalogue.index') }}">{{ __('catalogue.generated.t_50589dc3880be794') }}</a></div>
    <h1>{{ __('catalogue.generated.t_32b9d9a7d6c1eef2') }}</h1>
    <p class="intro">{{ __('catalogue.generated.t_6606bdedefc8062e') }}</p>
    <div class="actions">
        <a href="{{ route('catalogue.sources.create') }}" class="button">{{ __('catalogue.generated.t_5be03efb1e62857d') }}</a>
        <a href="{{ route('catalogue.contributors.index') }}" class="button secondary">{{ __('catalogue.generated.t_12aef68b6757111a') }}</a>
    </div>
</div>

<div class="card">
    <form method="get" action="{{ route('catalogue.sources.index') }}" style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem;">
        <div style="flex: 2; min-width: 200px;">
            <input type="text" name="q" value="{{ request('q') }}" placeholder="{{ __('catalogue.generated.t_f372a31cd0ae785f') }}">
        </div>
        <div style="flex: 1; min-width: 150px;">
            <select name="source_type" onchange="this.form.submit()">
                <option value="">{{ __('catalogue.generated.t_448b8c66e76bba81') }}</option>
                <option value="archive" {{ request('source_type') === 'archive' ? 'selected' : '' }}>{{ __('catalogue.generated.t_73d0e45ed6d9026a') }}</option>
                <option value="donor" {{ request('source_type') === 'donor' ? 'selected' : '' }}>{{ __('catalogue.generated.t_3aee1fdcf886167a') }}</option>
                <option value="collection" {{ request('source_type') === 'collection' ? 'selected' : '' }}>Deelcollectie</option>
                <option value="family" {{ request('source_type') === 'family' ? 'selected' : '' }}>Familiearchief</option>
                <option value="other" {{ request('source_type') === 'other' ? 'selected' : '' }}>Overig</option>
            </select>
        </div>
        <div>
            <button type="submit" class="secondary" style="margin: 0;">Zoeken</button>
            @if(request('q') || request('source_type'))
                <a href="{{ route('catalogue.sources.index') }}" class="button secondary" style="margin: 0;">Wissen</a>
            @endif
        </div>
    </form>

    @if($sources->isEmpty())
        <p>{{ __('catalogue.generated.t_95a4e43946316a3e') }}</p>
    @else
        <x-catalogue-table>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                    <th style="padding: .5rem;">Naam</th>
                    <th style="padding: .5rem;">Type</th>
                    <th style="padding: .5rem;">Referentiecode</th>
                    <th style="padding: .5rem; text-align: right;">{{ __('catalogue.generated.t_9d945313bfdd8bfa') }}</th>
                    <th style="padding: .5rem; text-align: right;">Acties</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sources as $s)
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td style="padding: .5rem;">
                            <strong><a href="{{ route('catalogue.sources.show', $s) }}">{{ $s->name }}</a></strong>
                        </td>
                        <td style="padding: .5rem;">
                            <span style="font-size: .85rem; padding: .2rem .5rem; background: var(--notice); border-radius: .25rem;">
                                {{ ucfirst($s->source_type) }}
                            </span>
                        </td>
                        <td style="padding: .5rem;"><code>{{ $s->reference_code ?: '—' }}</code></td>
                        <td style="padding: .5rem; text-align: right;">{{ $s->assets_count }}</td>
                        <td style="padding: .5rem; text-align: right;">
                            <a href="{{ route('catalogue.sources.edit', $s) }}" style="margin-right: .5rem;">Bewerken</a>
                            <a href="{{ route('catalogue.sources.show', $s) }}">Bekijken</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </x-catalogue-table>

        <div style="margin-top: 1rem;">
            {{ $sources->links() }}
        </div>
    @endif
</div>
@endsection
