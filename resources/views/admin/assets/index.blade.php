@extends('layouts.app')
@section('title', 'Foto’s beheren - Vistora')
@section('content')
<p class="eyebrow">{{ __('catalogue.generated.t_49964d7d8c8ecabc') }}</p><h1>{{ __('catalogue.generated.t_437769185346f168') }}</h1>
@include('catalogue.saved-searches')
@can('assets.create')
<section class="card">
    <p><a href="{{ route('admin.uploads.index') }}">{{ __('uploads.title') }}</a> — {{ __('uploads.intro') }}</p>
    <h2>{{ __('catalogue.generated.t_edebc0aa311251a7') }}</h2>
    <p>{{ __('catalogue.generated.t_2d586a3aa8412d1b') }} {{ number_format(config('ingest.max_upload_bytes') / 1048576) }} {{ __('catalogue.generated.t_15192451ab66121d') }}</p>
    <p>{{ __('catalogue.generated.t_1103b3a10a5b15af') }} {{ min((int) ini_get('max_file_uploads'), (int) config('ingest.max_batch_upload_files')) }} {{ __('catalogue.generated.t_6f714621064d450b') }}</p>
    <form id="upload-form" method="post" action="{{ route('admin.assets.store') }}" enctype="multipart/form-data" data-max-files="{{ config('ingest.max_batch_upload_files') }}" data-max-bytes="{{ config('ingest.max_upload_bytes') }}">
        @csrf
        <div id="drop-zone">
            <label for="files">{{ __('catalogue.generated.t_ca4e0e7e4ced8d19') }}</label>
            <input id="files" name="files[]" type="file" accept="image/jpeg,image/png,image/webp" multiple required>
        </div>
        <button id="upload-submit" type="submit">Uploaden</button>
    </form>
    <ul id="upload-results" aria-live="polite"></ul>
    @if(session('upload_results'))
        <ul>@foreach(session('upload_results') as $result)
            <li>{{ $result['name'] }}: @if($result['ok'])<a href="{{ $result['url'] }}">{{ __('catalogue.generated.t_a5f19743ec572eac') }}</a>@else{{ $result['error'] }}@endif</li>
        @endforeach</ul>
    @endif
    <noscript><p>{{ __('catalogue.generated.t_1115c9ca31e5edc2') }}</p></noscript>
    <p>{{ __('catalogue.generated.t_cf320a0073452ea6') }}</p>
</section>
<script src="/uploads.js" defer></script>
@endcan

<section class="card">
    <h2>{{ __('catalogue.generated.t_aa0bc3a4a310e7e0') }}</h2>
    <form method="get" action="{{ route('admin.assets.index') }}">
        <label for="missing">{{ __('daily.quality') }}</label>
        <select id="missing" name="missing">
            <option value="">{{ __('daily.all') }}</option>
            <option value="description" @selected(request('missing') === 'description')>{{ __('daily.description_missing') }}</option>
            <option value="dating" @selected(request('missing') === 'dating')>{{ __('daily.dating_missing') }}</option>
            <option value="collection" @selected(request('missing') === 'collection')>{{ __('daily.collection_missing') }}</option>
            <option value="rights" @selected(request('missing') === 'rights')>{{ __('daily.rights_missing') }}</option>
        </select>
        <div class="grid">
            <div>
                <label for="q">{{ __('catalogue.generated.t_e2341cb511de39f0') }}</label>
                <input id="q" name="q" value="{{ request('q') }}" maxlength="200" placeholder="{{ __('catalogue.generated.t_8e543c971556de16') }}">
            </div>
            <div>
                <label for="collection_id">{{ __('catalogue.generated.t_6d904ba5a82ecc90') }}</label>
                <select id="collection_id" name="collection_id">
                    <option value="">{{ __('catalogue.generated.t_e831441dc1b17408') }}</option>
                    @foreach($filterCollections as $fc)
                        <option value="{{ $fc->id }}" {{ request('collection_id') === $fc->id ? 'selected' : '' }}>{{ $fc->title }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid">
            <div>
                <label for="person_id">{{ __('catalogue.generated.t_66b5d5480e041d9e') }}</label>
                <select id="person_id" name="person_id">
                    <option value="">{{ __('catalogue.generated.t_3aeda3d01898785f') }}</option>
                    @foreach($filterPeople as $fp)
                        <option value="{{ $fp->id }}" {{ request('person_id') === $fp->id ? 'selected' : '' }}>{{ $fp->display_name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="location_id">Locatie</label>
                <select id="location_id" name="location_id">
                    <option value="">{{ __('catalogue.generated.t_77adbbc197ab40b5') }}</option>
                    @foreach($filterLocations as $fl)
                        <option value="{{ $fl->id }}" {{ request('location_id') === $fl->id ? 'selected' : '' }}>{{ $fl->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid">
            <div>
                <label for="tag_id">{{ __('catalogue.generated.t_c78ecfacce5562fd') }}</label>
                <select id="tag_id" name="tag_id">
                    <option value="">{{ __('catalogue.generated.t_cbaed51171702763') }}</option>
                    @foreach($filterTags as $ft)
                        <option value="{{ $ft->id }}" {{ request('tag_id') === $ft->id ? 'selected' : '' }}>{{ $ft->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="rights_status">Rechtenstatus</label>
                <select id="rights_status" name="rights_status">
                    <option value="">{{ __('catalogue.generated.t_a97117550a31143e') }}</option>
                    <option value="verified" {{ request('rights_status') === 'verified' ? 'selected' : '' }}>Geverifieerd</option>
                    <option value="unverified" {{ request('rights_status') === 'unverified' ? 'selected' : '' }}>Ongeverifieerd</option>
                    <option value="disputed" {{ request('rights_status') === 'disputed' ? 'selected' : '' }}>Betwist</option>
                </select>
            </div>
        </div>

        <div class="grid">
            <div>
                <label for="date_from">{{ __('catalogue.generated.t_d24c196c9816df0f') }}</label>
                <input type="date" id="date_from" name="date_from" value="{{ request('date_from') }}">
            </div>
            <div>
                <label for="date_to">{{ __('catalogue.generated.t_f5e46d1a1190153b') }}</label>
                <input type="date" id="date_to" name="date_to" value="{{ request('date_to') }}">
            </div>
        </div>

        <div class="actions">
            <button type="submit">{{ __('catalogue.generated.t_86346d0fa5173680') }}</button>
            <a href="{{ route('admin.assets.index') }}" class="button secondary">{{ __('catalogue.generated.t_b81cc74c63510f91') }}</a>
        </div>
    </form>
</section>

<section class="card">
    <h2>{{ __('catalogue.generated.t_378ff17ca6aa4115') }}{{ $assets->count() }} {{ __('catalogue.generated.t_9cf45551361bb037') }}</h2>
    <form method="get" action="{{ route('catalogue.bulk.confirm') }}">
        @if($assets->isNotEmpty())
            <div style="margin-bottom: 1rem;">
                <button type="submit">{{ __('catalogue.generated.t_0426fae1acf1c40d') }}</button>
            </div>
        @endif

        <ul class="asset-list">
        @forelse($assets as $asset)
            @php($file = $asset->files->first())
            <li>
                <label style="display: flex; align-items: center; gap: 0.5rem;">
                    <input type="checkbox" name="asset_ids[]" value="{{ $asset->id }}">
                    @if($file && isset($file->derivatives['preview300']))
                        <img class="thumbnail" loading="lazy" src="{{ route('admin.assets.media', [$asset, $file, 'preview300']) }}" alt="">
                    @endif
                    <div>
                        <a href="{{ route('admin.assets.show', $asset) }}">{{ $asset->title ?: $asset->accession_number }}</a>
                        <br>
                        <small>
                            {{ $asset->accession_number }} · {{ $asset->uploads->first()?->status ?? $file?->ingest_status ?? 'Geen upload' }} · {{ $asset->catalogue_status }} {{ __('catalogue.generated.t_d241bfeeceab15a2') }} {{ $asset->lock_version }}
                            @if($asset->date_display) · {{ $asset->date_display }} @elseif($asset->date_earliest) · {{ $asset->date_earliest->format('Y') }} @endif
                        </small>
                    </div>
                </label>
            </li>
        @empty
            <li>{{ __('catalogue.generated.t_30819f9f82d35bb9') }}</li>
        @endforelse
        </ul>

        @if($assets->isNotEmpty())
            <div style="margin-top: 1rem;">
                <button type="submit">{{ __('catalogue.generated.t_0426fae1acf1c40d') }}</button>
            </div>
        @endif
    </form>

    @if($nextCursor)
        @php($queryParams = array_merge(request()->query(), ['cursor' => $nextCursor]))
        <a class="button secondary" style="margin-top: 1rem; display: inline-block;" href="{{ route('admin.assets.index', $queryParams) }}">{{ __('catalogue.generated.t_78904cbff656c1f8') }}</a>
    @endif
</section>
@endsection
