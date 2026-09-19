@extends('layouts.app')
@section('title', ($asset->title ?: $asset->accession_number).__('publication.application_suffix'))
@section('content')
    @if($staffPreview ?? false)
        <p class="notice">{{ __('publishwork.preview_hint') }}</p>
    @else
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <link rel="alternate" type="application/ld+json" href="{{ route('iiif.manifest', $publication) }}" title="IIIF-manifest">
    <script type="application/ld+json">{!! json_encode($structuredData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif
    <p class="eyebrow">Foto</p>
    <h1>{{ $asset->title ?: $asset->accession_number }}</h1>
    @if($file)
        <figure class="viewer" data-viewer
                @unless($staffPreview ?? false) data-manifest-url="{{ route('iiif.manifest', $publication) }}" @endunless>
            <div class="viewer-viewport" id="viewer-viewport" role="region" tabindex="0"
                 aria-label="{{ __('publication.viewer.region') }}" aria-describedby="viewer-help">
                <img class="preview" id="viewer-image"
                     src="{{ ($staffPreview ?? false) ? route('admin.assets.media', [$asset, $file, 'preview1200']) : route('public.photo.media', [$publication, 'preview1200']) }}"
                     alt="{{ $asset->title ?: $asset->accession_number }}" data-zoom="1" data-pan-x="0" data-pan-y="0">
            </div>
            <figcaption class="viewer-controls">
                <button type="button" id="viewer-zoom-in" aria-controls="viewer-viewport">{{ __('publication.viewer.zoom_in') }}</button>
                <button type="button" id="viewer-zoom-out" aria-controls="viewer-viewport">{{ __('publication.viewer.zoom_out') }}</button>
                <button type="button" id="viewer-reset" aria-controls="viewer-viewport">{{ __('publication.viewer.reset') }}</button>
                <span id="viewer-help">{{ __('publication.viewer.help') }}</span>
                <span id="viewer-status" role="status"
                      data-loading="{{ __('publication.viewer.loading') }}"
                      data-loaded="{{ __('publication.viewer.loaded') }}"
                      data-fallback="{{ __('publication.viewer.fallback') }}"></span>
            </figcaption>
            @unless($staffPreview ?? false)
                <noscript><p><a href="{{ route('iiif.manifest', $publication) }}">{{ __('publication.viewer.manifest_link') }}</a></p></noscript>
            @endunless
        </figure>
    @endif
    @if($asset->description)<p class="intro">{{ $asset->description }}</p>@endif
    <dl class="meta">
        @if($publication->credit_line)<dt>Bronvermelding</dt><dd>{{ $publication->credit_line }}</dd>@endif
        @if($right?->rights_holder)<dt>Rechthebbende</dt><dd>{{ $right->rights_holder }}</dd>@endif
        @if($right?->rightsStatement)<dt>Rechtenstatus</dt><dd>{{ $right->rightsStatement->name }}</dd>@endif
        @if($asset->date_display)<dt>Datering</dt><dd>{{ $asset->date_display }}</dd>@endif
    </dl>

    @if($publication->download_policy === 'preview_only' && $file)
        @if($staffPreview ?? false)
        <p>{{ __('publication.generated.t_f8888469800ffc38') }} &middot; {{ __('publishwork.preview_only') }}</p>
        @else
        <a class="button" href="{{ route('public.photo.media', [$publication, 'preview2000', 'download' => 1]) }}">{{ __('publication.generated.t_f8888469800ffc38') }}</a>
        @endif
    @else
        <p><em>{{ __('publication.generated.t_e66bbc698b9b4c8e') }}</em></p>
    @endif

    @unless($staffPreview ?? false)
    <section class="card">
        <h2>{{ __('publication.generated.t_7ab43b6be6480d7f') }}</h2>
        <p><code id="permalink">{{ $canonicalUrl }}</code> <button type="button" id="copy-permalink" data-url="{{ $canonicalUrl }}">{{ __('publication.generated.t_0bb71606274bca18') }}</button></p>
        <ul class="share-links">
            <li><a rel="noopener" target="_blank" href="https://wa.me/?text={{ urlencode(($asset->title ?: $asset->accession_number).' '.$canonicalUrl) }}">{{ __('publication.generated.t_026fa7e0fe75a1a5') }}</a></li>
            <li><a href="mailto:?subject={{ urlencode($asset->title ?: $asset->accession_number) }}&amp;body={{ urlencode($canonicalUrl) }}">{{ __('publication.generated.t_e2cc2cd1412e51dd') }}</a></li>
            @if($file)<li><a href="{{ route('iiif.manifest', $publication) }}">{{ __('publication.viewer.manifest_link') }}</a></li>@endif
        </ul>
    </section>

    <section class="card">
        <h2>{{ __('publication.generated.t_914eeb13f64d1644') }}</h2>
        <p class="intro">{{ __('publication.generated.t_74cad71eb639c329') }}</p>
        <form method="post" action="{{ route('public.photo.suggest', $publication) }}">
            @csrf
            <label>Type
                <select name="suggestion_type" required>
                    <option value="identification">{{ __('publication.generated.t_d12c4fd3c482fab1') }}</option>
                    <option value="correction">{{ __('publication.generated.t_38940e16078f7042') }}</option>
                </select>
            </label>
            <label>{{ __('publication.generated.t_6e28f34f07059e2b') }} <textarea name="message" required minlength="5" maxlength="2000"></textarea></label>
            <label>{{ __('publication.generated.t_6fff638b9a5afce1') }} <input type="text" name="submitter_name" maxlength="200"></label>
            <label>{{ __('publication.generated.t_5ec1f95e570b3685') }} <input type="email" name="submitter_email" maxlength="255"></label>
            <div class="honeypot" aria-hidden="true">
                <label for="website">{{ __('publication.generated.t_74f5ced0193005a5') }}</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>
            <button type="submit">Versturen</button>
        </form>
    </section>

    @endunless
    <script src="/viewer.js" defer></script>
@endsection
