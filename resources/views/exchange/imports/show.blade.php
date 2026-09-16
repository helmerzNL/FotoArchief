@extends('layouts.app')
@section('title', 'Importvoorbeeld - FotoArchief')
@section('content')
@php($summary = $import->summary ?? [])
<p class="eyebrow">Uitwisseling</p><h1>Importvoorbeeld</h1>
<p class="intro">{{ $import->original_filename ?: 'CSV-import' }} · {{ $import->statusLabel() }}</p>
@if($import->status === 'failed' && $import->failure_reason)
    <div class="errors" role="alert"><strong>Import mislukt</strong><p>{{ $import->failure_reason }}</p></div>
@endif
@if($import->isBusy())
    <div class="notice" role="status">De controle of verwerking loopt. Vernieuw deze pagina; een actieve worker is vereist.</div>
@endif
<section class="card">
    <h2>Samenvatting</h2>
    <ul class="asset-list">
        <li>Rijen gelezen: {{ $summary['total'] ?? $import->row_count }}</li>
        <li>Klaar om bij te werken: {{ $summary['ready'] ?? 0 }}</li>
        <li>Geen wijziging nodig: {{ $summary['unchanged'] ?? 0 }}</li>
        <li>Met fouten (worden overgeslagen): {{ $summary['error'] ?? 0 }}</li>
        <li>Bijgewerkt: {{ $summary['applied'] ?? 0 }}</li>
        <li>Mislukt tijdens bijwerken: {{ $summary['failed'] ?? 0 }}</li>
    </ul>
    <p>Bestandscontrole (SHA-256): <span class="checksum">{{ $import->content_sha256 }}</span></p>
    <p>Schrijfstand: {{ $import->write_mode === 'fill_empty' ? 'Alleen lege velden aanvullen' : 'Ingevulde waarden overschrijven' }}</p>
</section>
<section class="card">
    <h2>Kolomherkenning</h2>
    <table>
        <caption>Welke kolom hoort bij welk veld</caption>
        <thead><tr><th scope="col">Kolom in bestand</th><th scope="col">Veld</th></tr></thead>
        <tbody>
        @forelse($import->column_mapping ?? [] as $column)
            <tr>
                <td>{{ $column['column'] }}</td>
                <td>@if($column['status'] === 'mapped'){{ $column['field'] }}@elseif($column['status'] === 'duplicate')Dubbele kolom, genegeerd@else Genegeerd @endif</td>
            </tr>
        @empty
            <tr><td colspan="2">Nog geen kolommen gelezen.</td></tr>
        @endforelse
        </tbody>
    </table>
</section>
<section class="card">
    <h2>Rijen (eerste {{ config('exchange.preview_rows') }})</h2>
    <table>
        <caption>Voorgestelde wijzigingen per rij</caption>
        <thead><tr><th scope="col">Rij</th><th scope="col">Archiefnummer</th><th scope="col">Status</th><th scope="col">Wijzigingen</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr>
                <td>{{ $row->row_number }}</td>
                <td>{{ $row->accession_number }}</td>
                <td>{{ ['ready' => 'Klaar', 'unchanged' => 'Ongewijzigd', 'error' => 'Fout', 'applied' => 'Bijgewerkt', 'failed' => 'Mislukt', 'pending' => 'Wacht'][$row->status] ?? $row->status }}</td>
                <td>
                    @foreach($row->changes ?? [] as $field => $change)
                        <div class="revision">{{ $field }}: <em>{{ is_array($change['before']) ? implode(', ', array_map('strval', $change['before'])) : ($change['before'] ?? '(leeg)') }}</em> &rarr; <strong>{{ is_array($change['after']) ? implode(', ', array_map('strval', $change['after'])) : ($change['after'] ?? '(leeg)') }}</strong></div>
                    @endforeach
                    @foreach($row->messages ?? [] as $message)
                        <div>{{ $message }}</div>
                    @endforeach
                </td>
            </tr>
        @empty
            <tr><td colspan="4">Nog geen rijen gecontroleerd.</td></tr>
        @endforelse
        </tbody>
    </table>
</section>
@can('assets.update')
@if(! $import->isBusy() && $import->status !== 'completed')
<section class="card">
    <h2>Opnieuw controleren</h2>
    <form method="post" action="{{ route('exchange.imports.analyse', $import) }}">
        @csrf
        <fieldset>
            <legend>Schrijfstand</legend>
            <label class="check"><input type="radio" name="write_mode" value="fill_empty" @checked($import->write_mode === 'fill_empty')> Alleen lege velden aanvullen</label>
            <label class="check"><input type="radio" name="write_mode" value="overwrite" @checked($import->write_mode === 'overwrite')> Ingevulde waarden overschrijven</label>
        </fieldset>
        <button class="secondary" type="submit">Voorbeeld opnieuw maken</button>
    </form>
</section>
@endif
@if(in_array($import->status, ['analysed', 'failed'], true) && ($summary['ready'] ?? 0) > 0)
<section class="card">
    <h2>Import bevestigen</h2>
    <p>Hierna worden {{ $summary['ready'] }} rijen bijgewerkt. Rijen met fouten blijven ongemoeid. Elke wijziging wordt per foto vastgelegd in de geschiedenis.</p>
    <form method="post" action="{{ route('exchange.imports.confirm', $import) }}">
        @csrf
        <input type="hidden" name="checksum" value="{{ $import->content_sha256 }}">
        <button type="submit">Ja, deze {{ $summary['ready'] }} rijen bijwerken</button>
    </form>
</section>
@endif
@endcan
<p><a href="{{ route('exchange.index') }}">Terug naar uitwisseling</a></p>
@endsection