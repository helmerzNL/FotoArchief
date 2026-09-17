@extends('layouts.app')
@section('title', 'Personen & Organisaties — FotoArchief')
@section('content')
<div class="card">
    <div class="eyebrow"><a href="{{ route('catalogue.index') }}">{{ __('catalogue.generated.t_50589dc3880be794') }}</a></div>
    <h1>{{ __('catalogue.generated.t_57e6c76d611f6dff') }}</h1>
    <p class="intro">{{ __('catalogue.generated.t_fde87d53cad2c46e') }}</p>
    <div class="actions">
        <a href="{{ route('catalogue.people.create') }}" class="button">{{ __('catalogue.generated.t_ab654dad260b9610') }}</a>
    </div>
</div>

<div class="card">
    <form method="get" action="{{ route('catalogue.people.index') }}" style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem;">
        <div style="flex: 2; min-width: 200px;">
            <input type="text" name="q" value="{{ request('q') }}" placeholder="{{ __('catalogue.generated.t_6b03d2f87b548260') }}">
        </div>
        <div style="flex: 1; min-width: 150px;">
            <select name="entity_type" onchange="this.form.submit()">
                <option value="">{{ __('catalogue.generated.t_448b8c66e76bba81') }}</option>
                <option value="person" {{ request('entity_type') === 'person' ? 'selected' : '' }}>{{ __('catalogue.generated.t_d850c8adb2ea4071') }}</option>
                <option value="organisation" {{ request('entity_type') === 'organisation' ? 'selected' : '' }}>{{ __('catalogue.generated.t_99d32bc5a647de66') }}</option>
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
        <p>{{ __('catalogue.generated.t_1cea98bd4dda9cf0') }}</p>
    @else
        <x-catalogue-table>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                    <th style="padding: .5rem;">Naam</th>
                    <th style="padding: .5rem;">Type</th>
                    <th style="padding: .5rem; text-align: right;">Aliassen</th>
                    <th style="padding: .5rem; text-align: right;">{{ __('catalogue.generated.t_9d945313bfdd8bfa') }}</th>
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
        </x-catalogue-table>

        <div style="margin-top: 1rem;">
            {{ $people->links() }}
        </div>
    @endif
</div>
@endsection
