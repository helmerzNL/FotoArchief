@extends('layouts.app')
@section('title', 'Uitwisseling - FotoArchief')
@section('content')
<p class="eyebrow">{{ __('exchange.generated.t_49964d7d8c8ecabc') }}</p><h1>Uitwisseling</h1>
<p class="intro">{{ __('exchange.generated.t_0e5b86fc09892257') }}</p>
@can('assets.update')
<section class="card">
    <h2>{{ __('exchange.generated.t_7955a59a39b93986') }}</h2>
    <p>Maximaal {{ number_format(config('exchange.max_import_bytes') / 1048576, 1) }} {{ __('exchange.generated.t_6d280f7a8b27c3fa') }} {{ number_format(config('exchange.max_import_rows')) }} {{ __('exchange.generated.t_4597a592325c8aa3') }} <code>accession_number</code> en <code>lock_version</code>{{ __('exchange.generated.t_540a90c19771505d') }} <code>title</code>, <code>description</code>, <code>date_precision</code>, <code>date_earliest</code>, <code>date_latest</code>, <code>date_display</code>, <code>tags</code>, <code>rights_holder</code>, <code>rights_status</code>, <code>rights_note</code>.</p>
    <form method="post" action="{{ route('exchange.imports.store') }}" enctype="multipart/form-data">
        @csrf
        <label for="file">CSV-bestand</label>
        <input id="file" name="file" type="file" accept=".csv,text/csv,text/plain" required>
        <fieldset>
            <legend>{{ __('exchange.generated.t_cfa244e0644485c2') }}</legend>
            <label class="check"><input type="radio" name="write_mode" value="fill_empty" checked> {{ __('exchange.generated.t_ebce0b8a63d3672d') }}</label>
            <label class="check"><input type="radio" name="write_mode" value="overwrite"> {{ __('exchange.generated.t_aa92eaa74419741e') }}</label>
        </fieldset>
        <p>{{ __('exchange.generated.t_c5e3167d0ae9bcba') }}</p>
        <button type="submit">{{ __('exchange.generated.t_da75938a649fdb40') }}</button>
    </form>
</section>
@endcan
@can('exports.create')
<section class="card">
    <h2>Exporteren</h2>
    <p>{{ __('exchange.generated.t_8b78d674e8788d0a') }} {{ number_format(config('exchange.max_export_assets')) }} {{ __('exchange.generated.t_7d80f105e50162b9') }} {{ config('exchange.download_ttl_minutes') }} {{ __('exchange.generated.t_32c21b78ea936f54') }} {{ config('exchange.export_ttl_minutes') }} {{ __('exchange.generated.t_f50afd92b7c876a9') }}</p>
    <form method="post" action="{{ route('exchange.exports.store') }}">
        @csrf
        <label for="export_type">Formaat</label>
        <select id="export_type" name="export_type">
            <option value="metadata_csv">{{ __('exchange.generated.t_c23db678d7ad91f1') }}</option>
            <option value="metadata_json">{{ __('exchange.generated.t_e3d4087cf8e47603') }}</option>
            <option value="package_zip">{{ __('exchange.generated.t_bae7fe28cb95596a') }}</option>
        </select>
        <fieldset>
            <legend>{{ __('exchange.generated.t_dc6c7cc8b5bbe221') }}</legend>
            <label class="check"><input type="radio" name="scope" value="selection" checked> {{ __('exchange.generated.t_53f507a1c88a9ecd') }}</label>
            <label class="check"><input type="radio" name="scope" value="all"> {{ __('exchange.generated.t_365cf54ec60cb26c') }}</label>
        </fieldset>
        <fieldset>
            <legend>{{ __('exchange.generated.t_e3f1a836ba42da65') }} {{ $assets->count() }} {{ __('exchange.generated.t_33043cd8f638813b') }}</legend>
            @forelse($assets as $asset)
                <label class="check"><input type="checkbox" name="asset_ids[]" value="{{ $asset->id }}"> {{ $asset->title ?: $asset->accession_number }} <small>{{ $asset->accession_number }}</small></label>
            @empty
                <p>{{ __('exchange.generated.t_194c1e6cf11ef764') }}</p>
            @endforelse
        </fieldset>
        <button type="submit">{{ __('exchange.generated.t_2eb6c0235eb6f6cf') }}</button>
    </form>
</section>
<section class="card">
    <h2>{{ __('exchange.generated.t_3bbb3c76a1cf578e') }}</h2>
    <ul class="asset-list">
    @forelse($exports as $export)
        <li>
            <a href="{{ route('exchange.exports.show', $export) }}">{{ $export->typeLabel() }}</a>
            <small>{{ $export->created_at?->format('d-m-Y H:i') }} {{ __('exchange.generated.t_34ba21340c9968af') }} {{ $export->statusLabel() }} &middot; {{ $export->asset_count }} {{ __('exchange.generated.t_7fd116f331a1ff4a') }}</small>
        </li>
    @empty
        <li>{{ __('exchange.generated.t_b953b721802dbba6') }}</li>
    @endforelse
    </ul>
</section>
@endcan
<section class="card">
    <h2>{{ __('exchange.generated.t_c6fd6f53fa9c224f') }}</h2>
    <ul class="asset-list">
    @forelse($imports as $import)
        <li>
            <a href="{{ route('exchange.imports.show', $import) }}">{{ $import->original_filename ?: 'CSV-import' }}</a>
            <small>{{ $import->created_at?->format('d-m-Y H:i') }} {{ __('exchange.generated.t_34ba21340c9968af') }} {{ $import->statusLabel() }} · {{ $import->row_count }} {{ __('exchange.fragments.rows') }}</small>
        </li>
    @empty
        <li>{{ __('exchange.generated.t_ec5e32031a68e4f6') }}</li>
    @endforelse
    </ul>
</section>
@endsection