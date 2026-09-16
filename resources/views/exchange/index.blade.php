@extends('layouts.app')
@section('title', 'Uitwisseling - FotoArchief')
@section('content')
<p class="eyebrow">Privéarchief</p><h1>Uitwisseling</h1>
<p class="intro">Metadata in bulk bijwerken met een CSV-bestand. Een import wijzigt nooit iets voordat je het voorbeeld hebt gecontroleerd en bevestigd, en maakt nooit nieuwe foto’s aan.</p>
@can('assets.update')
<section class="card">
    <h2>CSV importeren</h2>
    <p>Maximaal {{ number_format(config('exchange.max_import_bytes') / 1048576, 1) }} MiB en {{ number_format(config('exchange.max_import_rows')) }} rijen, opgeslagen als CSV UTF-8. Vereiste kolommen: <code>accession_number</code> en <code>lock_version</code>. Optioneel: <code>title</code>, <code>description</code>, <code>date_precision</code>, <code>date_earliest</code>, <code>date_latest</code>, <code>date_display</code>, <code>tags</code>, <code>rights_holder</code>, <code>rights_status</code>, <code>rights_note</code>.</p>
    <form method="post" action="{{ route('exchange.imports.store') }}" enctype="multipart/form-data">
        @csrf
        <label for="file">CSV-bestand</label>
        <input id="file" name="file" type="file" accept=".csv,text/csv,text/plain" required>
        <fieldset>
            <legend>Hoe gaan we om met bestaande gegevens?</legend>
            <label class="check"><input type="radio" name="write_mode" value="fill_empty" checked> Alleen lege velden aanvullen (veilig)</label>
            <label class="check"><input type="radio" name="write_mode" value="overwrite"> Ingevulde waarden overschrijven</label>
        </fieldset>
        <p>Lege cellen wissen nooit bestaande gegevens, in beide standen.</p>
        <button type="submit">Bestand controleren</button>
    </form>
</section>
@endcan
<section class="card">
    <h2>Mijn imports</h2>
    <ul class="asset-list">
    @forelse($imports as $import)
        <li>
            <a href="{{ route('exchange.imports.show', $import) }}">{{ $import->original_filename ?: 'CSV-import' }}</a>
            <small>{{ $import->created_at?->format('d-m-Y H:i') }} · Status: {{ $import->statusLabel() }} · {{ $import->row_count }} rijen</small>
        </li>
    @empty
        <li>Nog geen imports.</li>
    @endforelse
    </ul>
</section>
@endsection