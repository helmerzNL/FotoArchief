@extends('layouts.app')
@section('title', 'Publicatie - FotoArchief')
@section('content')
    <p class="eyebrow">Publicatieworkflow</p>
    <h1>Publicatie</h1>
    <p class="intro">{{ __('publication.generated.t_32e9b30a1690908d') }}</p>
    <x-table-scroll label="Publicaties, horizontaal scrollbaar op smalle schermen">
        <table>
            <thead><tr><th>Titel</th><th>Status</th><th>Actie</th></tr></thead>
            <tbody>
            @foreach($assets as $asset)
                <tr>
                    <td>{{ $asset->title ?? $asset->accession_number }}</td>
                    <td>{{ $asset->publication?->status ?? 'geen' }}@if($asset->publication?->needsReReview()) &middot; <strong>{{ __('publication.generated.t_374b7b02d96b0e97') }}</strong>@endif</td>
                    <td><a href="{{ route('admin.publications.show', $asset) }}">Openen</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </x-table-scroll>
@endsection
