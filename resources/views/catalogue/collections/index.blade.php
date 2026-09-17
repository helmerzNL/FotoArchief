@extends('layouts.app')
@section('title', 'Collecties & Albums — FotoArchief')
@section('content')
<div class="card">
    <div class="eyebrow"><a href="{{ route('catalogue.index') }}">{{ __('catalogue.generated.t_50589dc3880be794') }}</a></div>
    <h1>{{ __('catalogue.generated.t_498f6ac003fac8f7') }}</h1>
    <p class="intro">{{ __('catalogue.generated.t_334a91d3f00b43e4') }}</p>
    <div class="actions">
        <a href="{{ route('catalogue.collections.create') }}" class="button">{{ __('catalogue.generated.t_7ed9a474a9376764') }}</a>
    </div>
</div>

<div class="card">
    <h2>Collectieoverzicht</h2>
    @if($collections->isEmpty())
        <p>{{ __('catalogue.generated.t_fb25d3046dac328d') }}</p>
    @else
        <x-catalogue-table>
        <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                    <th style="padding: .5rem;">Titel</th>
                    <th style="padding: .5rem;">Type</th>
                    <th style="padding: .5rem;">Hoofdcollectie</th>
                    <th style="padding: .5rem; text-align: right;">Subcollecties</th>
                    <th style="padding: .5rem; text-align: right;">{{ __('catalogue.generated.t_437769185346f168') }}</th>
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
        </x-catalogue-table>
    @endif
</div>
@endsection
