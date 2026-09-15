@extends('layouts.app')
@section('title', 'Foto’s beheren - FotoArchief')
@section('content')
<p class="eyebrow">Privéarchief</p><h1>Foto’s</h1>
@can('assets.create')
<section class="card">
    <h2>Nieuwe foto’s uploaden</h2>
    <p>JPEG, PNG of WebP, maximaal {{ number_format(config('ingest.max_upload_bytes') / 1048576) }} MiB per bestand. Geen TIFF, PDF of SVG. Foto’s blijven concept en privé.</p>
    <p>Grote bestanden worden met JavaScript één voor één verstuurd. Zonder JavaScript geldt ook de totale uploadlimiet van je webhoster (Docker: 110 MiB per verzoek) en maximaal {{ min((int) ini_get('max_file_uploads'), (int) config('ingest.max_batch_upload_files')) }} bestanden per verzending. Selecteer niet meer: PHP kan extra bestanden overslaan.</p>
    <form id="upload-form" method="post" action="{{ route('admin.assets.store') }}" enctype="multipart/form-data" data-max-files="{{ config('ingest.max_batch_upload_files') }}" data-max-bytes="{{ config('ingest.max_upload_bytes') }}">
        @csrf
        <div id="drop-zone">
            <label for="files">Kies bestanden of sleep ze hierheen</label>
            <input id="files" name="files[]" type="file" accept="image/jpeg,image/png,image/webp" multiple required>
        </div>
        <button id="upload-submit" type="submit">Uploaden</button>
    </form>
    <ul id="upload-results" aria-live="polite"></ul>
    @if(session('upload_results'))
        <ul>@foreach(session('upload_results') as $result)
            <li>{{ $result['name'] }}: @if($result['ok'])<a href="{{ $result['url'] }}">Ontvangen, bekijk verwerking</a>@else{{ $result['error'] }}@endif</li>
        @endforeach</ul>
    @endif
    <noscript><p>JavaScript staat uit. Je kunt meerdere bestanden selecteren; resultaten verschijnen na verzending.</p></noscript>
    <p>Verwerking vereist een actieve worker. Niet gescand betekent nooit schoon of publiceerbaar.</p>
</section>
<script src="/uploads.js" defer></script>
@endcan
<form class="actions" method="get">
    <label for="q">Zoeken op titel of archiefnummer</label>
    <input id="q" name="q" value="{{ request('q') }}" maxlength="200">
    <button class="secondary">Zoeken</button>
    <a href="{{ route('admin.assets.index') }}">Vernieuwen / filters wissen</a>
</form>
<section class="card"><h2>Archief</h2>
    <ul class="asset-list">
    @forelse($assets as $asset)
        @php($file = $asset->files->first())
        <li>
            @if($file && isset($file->derivatives['preview300']))
                <img class="thumbnail" loading="lazy" src="{{ route('admin.assets.media', [$asset, $file, 'preview300']) }}" alt="">
            @endif
            <a href="{{ route('admin.assets.show', $asset) }}">{{ $asset->title ?: $asset->accession_number }}</a>
            <small>{{ $asset->accession_number }} · {{ $asset->uploads->first()?->status ?? $file?->ingest_status ?? 'Geen upload' }} · Concept / privé</small>
        </li>
    @empty
        <li>Geen foto’s gevonden binnen jouw toegang.</li>
    @endforelse
    </ul>
    @if($nextCursor)<a class="button secondary" href="{{ route('admin.assets.index', array_filter(['q' => request('q'), 'cursor' => $nextCursor])) }}">Volgende pagina</a>@endif
</section>
@endsection
