@extends('layouts.app')
@section('title', 'Suggesties - FotoArchief')
@section('content')
    <p class="eyebrow">Bezoekersbijdragen</p>
    <h1>Suggesties</h1>
    <p class="intro">Correcties en identificaties van bezoekers, ter beoordeling. Een goedkeuring past de metadata niet automatisch aan.</p>
    <form method="get" class="actions">
        <label for="status">Status</label>
        <select id="status" name="status" onchange="this.form.submit()">
            <option value="pending" @selected($status === 'pending')>In behandeling</option>
            <option value="accepted" @selected($status === 'accepted')>Geaccepteerd</option>
            <option value="rejected" @selected($status === 'rejected')>Afgewezen</option>
            <option value="all" @selected($status === 'all')>Alles</option>
        </select>
    </form>
    <x-table-scroll label="Suggesties, horizontaal scrollbaar op smalle schermen">
        <table>
            <thead><tr><th>Foto</th><th>Type</th><th>Bericht</th><th>Status</th><th>Actie</th></tr></thead>
            <tbody>
            @forelse($suggestions as $suggestion)
                <tr>
                    <td>{{ $suggestion->asset?->title ?? $suggestion->asset?->accession_number }}</td>
                    <td>{{ $suggestion->suggestion_type }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($suggestion->message, 80) }}</td>
                    <td>{{ $suggestion->status }}</td>
                    <td><a href="{{ route('admin.suggestions.show', $suggestion) }}">Openen</a></td>
                </tr>
            @empty
                <tr><td colspan="5">Geen suggesties gevonden.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-table-scroll>
@endsection
