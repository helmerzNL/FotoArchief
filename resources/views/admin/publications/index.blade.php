@extends('layouts.app')
@section('title', 'Publicatie - Vistora')
@section('content')
    <p class="eyebrow">Publicatieworkflow</p>
    <h1>Publicatie</h1>
    <p class="intro">{{ __('publication.generated.t_32e9b30a1690908d') }}</p>
    <p><a href="{{ route('admin.publications.index', ['embargo' => 1]) }}">{{ __('publishwork.embargo_overview') }}</a> &middot; <a href="{{ route('admin.publications.index') }}">{{ __('daily.all') }}</a></p>
    <form method="post" action="{{ route('admin.publications.bulk.preview') }}">
    @csrf
    <x-table-scroll label="Publicaties, horizontaal scrollbaar op smalle schermen">
        <table>
            <thead><tr><th>{{ __('publishwork.selection') }}</th><th>Titel</th><th>Status</th><th>{{ __('publishwork.embargo') }}</th><th>Actie</th></tr></thead>
            <tbody>
            @foreach($assets as $asset)
                <tr>
                    <td>@if(auth()->user()->hasPermission('assets.publish'))<label><input type="checkbox" name="asset_ids[]" value="{{ $asset->id }}"> {{ $asset->accession_number }}</label>@endif</td>
                    <td>{{ $asset->title ?? $asset->accession_number }}</td>
                    <td>{{ $asset->publication?->status ?? 'geen' }}@if($asset->publication?->needsReReview()) &middot; <strong>{{ __('publication.generated.t_374b7b02d96b0e97') }}</strong>@endif</td>
                    <td>{{ $asset->publication?->embargo_until?->format('Y-m-d') }}<ul>@foreach($reviews->checklist($asset) as $key => $passed)@if(!$passed)<li>{{ __('publishwork.checks')[$key] }}</li>@endif @endforeach</ul></td>
                    <td><a href="{{ route('admin.publications.show', $asset) }}">Openen</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </x-table-scroll>
    @if(auth()->user()->hasPermission('assets.publish'))
        <p>{{ __('publishwork.bulk_hint') }}</p>
        <label for="publication-decision">{{ __('publishwork.decision') }}</label>
        <select id="publication-decision" name="decision"><option value="publish">{{ __('publishwork.publish') }}</option><option value="reject">{{ __('publishwork.reject') }}</option></select>
        <label for="publication-reason">{{ __('publishwork.reason') }}</label>
        <textarea id="publication-reason" name="reason" maxlength="2000"></textarea>
        <button type="submit">{{ __('daily.preview') }}</button>
    @endif
    </form>
    {{ $assets->links() }}
@endsection
