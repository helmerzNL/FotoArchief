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
@can('exports.create')
<section class="card">
    <h2>Exporteren</h2>
    <p>Metadata als JSON of CSV, of een volledig pakket met de originele bestanden, afgeleiden, een manifest en controlegetallen. Een export bevat alleen foto&rsquo;s die je op dat moment mag inzien, maximaal {{ number_format(config('exchange.max_export_assets')) }} per keer. Downloadlinks zijn persoonlijk en {{ config('exchange.download_ttl_minutes') }} minuten geldig; het bestand zelf wordt na {{ config('exchange.export_ttl_minutes') }} minuten opgeruimd.</p>
    <form method="post" action="{{ route('exchange.exports.store') }}">
        @csrf
        <label for="export_type">Formaat</label>
        <select id="export_type" name="export_type">
            <option value="metadata_csv">Metadata (CSV, geschikt om weer te importeren)</option>
            <option value="metadata_json">Metadata (JSON)</option>
            <option value="package_zip">Volledig pakket (ZIP met originelen en afgeleiden)</option>
        </select>
        <fieldset>
            <legend>Welke foto&rsquo;s?</legend>
            <label class="check"><input type="radio" name="scope" value="selection" checked> Alleen de aangevinkte foto&rsquo;s</label>
            <label class="check"><input type="radio" name="scope" value="all"> Alle foto&rsquo;s binnen mijn toegang</label>
        </fieldset>
        <fieldset>
            <legend>Selectie (laatste {{ $assets->count() }} foto&rsquo;s)</legend>
            @forelse($assets as $asset)
                <label class="check"><input type="checkbox" name="asset_ids[]" value="{{ $asset->id }}"> {{ $asset->title ?: $asset->accession_number }} <small>{{ $asset->accession_number }}</small></label>
            @empty
                <p>Nog geen foto&rsquo;s binnen jouw toegang.</p>
            @endforelse
        </fieldset>
        <button type="submit">Export aanvragen</button>
    </form>
</section>
<section class="card">
    <h2>Mijn exports</h2>
    <ul class="asset-list">
    @forelse($exports as $export)
        <li>
            <a href="{{ route('exchange.exports.show', $export) }}">{{ $export->typeLabel() }}</a>
            <small>{{ $export->created_at?->format('d-m-Y H:i') }} &middot; Status: {{ $export->statusLabel() }} &middot; {{ $export->asset_count }} foto&rsquo;s</small>
        </li>
    @empty
        <li>Nog geen exports.</li>
    @endforelse
    </ul>
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