@extends('layouts.app')
@section('title', 'Foto’s beheren - Vistora')
@section('content')
<div class="page-header">
    <div>
        <p class="eyebrow">{{ __('catalogue.generated.t_49964d7d8c8ecabc') }}</p>
        <h1>{{ __('catalogue.generated.t_437769185346f168') }}</h1>
    </div>
    @can('assets.create')
    <div class="page-actions"><a class="button" href="#upload">{{ __('catalogue.fragments.upload_action') }}</a></div>
    @endcan
</div>
@php($advancedActive = collect(['missing', 'person_id', 'location_id', 'tag_id', 'rights_status', 'date_from', 'date_to'])->contains(fn ($key) => filled(request($key))))
<section class="card">
    <h2 class="sr-only">{{ __('catalogue.generated.t_aa0bc3a4a310e7e0') }}</h2>
    <form method="get" action="{{ route('admin.assets.index') }}">
        <div class="filter-row">
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
            <button type="submit">{{ __('catalogue.generated.t_86346d0fa5173680') }}</button>
        </div>
        <details class="more-filters" @if($advancedActive) open @endif>
            <summary>{{ __('catalogue.fragments.more_filters') }}</summary>
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
                <a href="{{ route('admin.assets.index') }}" class="button secondary">{{ __('catalogue.generated.t_b81cc74c63510f91') }}</a>
            </div>
        </details>
    </form>
</section>

@include('catalogue.saved-searches')
<section class="card">
    <h2>{{ __('catalogue.generated.t_378ff17ca6aa4115') }}{{ $assets->count() }} {{ __('catalogue.generated.t_9cf45551361bb037') }}</h2>
    <form method="get" action="{{ route('catalogue.bulk.confirm') }}">
        @if($assets->isNotEmpty())
            <div class="bulk-bar">
                <button type="submit">{{ __('catalogue.generated.t_0426fae1acf1c40d') }}</button>
            </div>
        @endif

        <ul class="photo-grid">
        @forelse($assets as $asset)
            @php($file = $asset->files->first())
            @php($label = $asset->title ?: $asset->accession_number)
            <li class="photo-card">
                <label class="photo-select">
                    <input type="checkbox" name="asset_ids[]" value="{{ $asset->id }}">
                    <span class="sr-only">{{ __('catalogue.fragments.select_photo', ['title' => $label]) }}</span>
                </label>
                <a href="{{ route('admin.assets.show', $asset) }}" tabindex="-1" aria-hidden="true">
                    @if($file && isset($file->derivatives['preview300']))
                        <img class="photo-thumb" loading="lazy" src="{{ route('admin.assets.media', [$asset, $file, 'preview300']) }}" alt="">
                    @else
                        <span class="photo-thumb-empty"><svg class="icon" aria-hidden="true" focusable="false"><use href="#i-image"/></svg></span>
                    @endif
                </a>
                <div class="photo-body">
                    <a class="photo-title" href="{{ route('admin.assets.show', $asset) }}">{{ $label }}</a>
                    <span class="photo-meta">
                        {{ $asset->accession_number }}
                        @if($asset->date_display) · {{ $asset->date_display }} @elseif($asset->date_earliest) · {{ $asset->date_earliest->format('Y') }} @endif
                    </span>
                    <span>
                        @if($asset->uploads->first()?->status ?? $file?->ingest_status)
                            <x-status-badge :status="$asset->uploads->first()?->status ?? $file?->ingest_status" />
                        @else
                            <span class="photo-meta">{{ __('catalogue.fragments.no_upload') }}</span>
                        @endif
                    </span>
                </div>
            </li>
        @empty
            <li class="empty-state card">{{ __('catalogue.generated.t_30819f9f82d35bb9') }}</li>
        @endforelse
        </ul>

        @if($assets->isNotEmpty())
            <div class="bulk-bar" style="margin-top: 1rem;">
                <button type="submit">{{ __('catalogue.generated.t_0426fae1acf1c40d') }}</button>
            </div>
        @endif
    </form>

    @if($nextCursor)
        @php($queryParams = array_merge(request()->query(), ['cursor' => $nextCursor]))
        <a class="button secondary" href="{{ route('admin.assets.index', $queryParams) }}">{{ __('catalogue.generated.t_78904cbff656c1f8') }}</a>
    @endif
</section>

@can('assets.create')
<section class="card" id="upload">
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
@endsection
