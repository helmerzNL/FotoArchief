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

<section class="card">
    <h2>Geavanceerd zoeken &amp; filteren</h2>
    <form method="get" action="{{ route('admin.assets.index') }}">
        <div class="grid">
            <div>
                <label for="q">Zoekterm (titel, nummer, beschrijving)</label>
                <input id="q" name="q" value="{{ request('q') }}" maxlength="200" placeholder="bijv. Marktplein of FA-01J...">
            </div>
            <div>
                <label for="collection_id">Collectie / Album</label>
                <select id="collection_id" name="collection_id">
                    <option value="">Alle collecties</option>
                    @foreach($filterCollections as $fc)
                        <option value="{{ $fc->id }}" {{ request('collection_id') === $fc->id ? 'selected' : '' }}>{{ $fc->title }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid">
            <div>
                <label for="person_id">Persoon / Organisatie</label>
                <select id="person_id" name="person_id">
                    <option value="">Alle personen/organisaties</option>
                    @foreach($filterPeople as $fp)
                        <option value="{{ $fp->id }}" {{ request('person_id') === $fp->id ? 'selected' : '' }}>{{ $fp->display_name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="location_id">Locatie</label>
                <select id="location_id" name="location_id">
                    <option value="">Alle locaties</option>
                    @foreach($filterLocations as $fl)
                        <option value="{{ $fl->id }}" {{ request('location_id') === $fl->id ? 'selected' : '' }}>{{ $fl->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid">
            <div>
                <label for="tag_id">Tag / Trefwoord</label>
                <select id="tag_id" name="tag_id">
                    <option value="">Alle tags</option>
                    @foreach($filterTags as $ft)
                        <option value="{{ $ft->id }}" {{ request('tag_id') === $ft->id ? 'selected' : '' }}>{{ $ft->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="rights_status">Rechtenstatus</label>
                <select id="rights_status" name="rights_status">
                    <option value="">Alle statussen</option>
                    <option value="verified" {{ request('rights_status') === 'verified' ? 'selected' : '' }}>Geverifieerd</option>
                    <option value="unverified" {{ request('rights_status') === 'unverified' ? 'selected' : '' }}>Ongeverifieerd</option>
                    <option value="disputed" {{ request('rights_status') === 'disputed' ? 'selected' : '' }}>Betwist</option>
                </select>
            </div>
        </div>

        <div class="grid">
            <div>
                <label for="date_from">Datum vanaf (YYYY-MM-DD)</label>
                <input type="date" id="date_from" name="date_from" value="{{ request('date_from') }}">
            </div>
            <div>
                <label for="date_to">Datum tot (YYYY-MM-DD)</label>
                <input type="date" id="date_to" name="date_to" value="{{ request('date_to') }}">
            </div>
        </div>

        <div class="actions">
            <button type="submit">Filters toepassen</button>
            <a href="{{ route('admin.assets.index') }}" class="button secondary">Filters wissen</a>
        </div>
    </form>
</section>

<section class="card">
    <h2>Archieffoto’s ({{ $assets->count() }} op deze pagina)</h2>
    <ul class="asset-list">
    @forelse($assets as $asset)
        @php($file = $asset->files->first())
        <li>
            @if($file && isset($file->derivatives['preview300']))
                <img class="thumbnail" loading="lazy" src="{{ route('admin.assets.media', [$asset, $file, 'preview300']) }}" alt="">
            @endif
            <a href="{{ route('admin.assets.show', $asset) }}">{{ $asset->title ?: $asset->accession_number }}</a>
            <small>
                {{ $asset->accession_number }} · {{ $asset->uploads->first()?->status ?? $file?->ingest_status ?? 'Geen upload' }} · {{ $asset->catalogue_status }}
                @if($asset->date_display) · {{ $asset->date_display }} @elseif($asset->date_earliest) · {{ $asset->date_earliest->format('Y') }} @endif
            </small>
        </li>
    @empty
        <li>Geen foto’s gevonden binnen jouw zoekopdracht en toegang.</li>
    @endforelse
    </ul>
    @if($nextCursor)
        @php($queryParams = array_merge(request()->query(), ['cursor' => $nextCursor]))
        <a class="button secondary" href="{{ route('admin.assets.index', $queryParams) }}">Volgende pagina</a>
    @endif
</section>
@endsection
