@extends('layouts.app')
@section('title', ($asset->title ?: $asset->accession_number).' - FotoArchief')
@section('content')
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <script type="application/ld+json">{!! json_encode($structuredData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
    <p class="eyebrow">Foto</p>
    <h1>{{ $asset->title ?: $asset->accession_number }}</h1>
    @if($file)
        <figure class="viewer" data-viewer>
            <img class="preview" id="viewer-image" tabindex="0" role="button"
                 aria-pressed="false" aria-label="Klik om in of uit te zoomen"
                 src="{{ route('public.photo.media', [$publication, 'preview1200']) }}" alt="">
            <figcaption>
                <button type="button" id="viewer-zoom" aria-controls="viewer-image">Vergroten / verkleinen</button>
            </figcaption>
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
        <a class="button" href="{{ route('public.photo.media', [$publication, 'preview2000', 'download' => 1]) }}">Download voorbeeldweergave</a>
    @else
        <p><em>Downloaden is niet beschikbaar voor deze foto.</em></p>
    @endif

    <section class="card">
        <h2>Permalink en delen</h2>
        <p><code id="permalink">{{ $canonicalUrl }}</code> <button type="button" id="copy-permalink" data-url="{{ $canonicalUrl }}">Kopieer link</button></p>
        <ul class="share-links">
            <li><a rel="noopener" target="_blank" href="https://wa.me/?text={{ urlencode(($asset->title ?: $asset->accession_number).' '.$canonicalUrl) }}">Delen via WhatsApp</a></li>
            <li><a href="mailto:?subject={{ urlencode($asset->title ?: $asset->accession_number) }}&amp;body={{ urlencode($canonicalUrl) }}">Delen via e-mail</a></li>
        </ul>
    </section>

    <section class="card">
        <h2>Correctie of naam doorgeven</h2>
        <p class="intro">Herken je iets op deze foto, of klopt er iets niet? Laat het ons weten. Een medewerker beoordeelt elke suggestie voordat er iets wijzigt.</p>
        <form method="post" action="{{ route('public.photo.suggest', $publication) }}">
            @csrf
            <label>Type
                <select name="suggestion_type" required>
                    <option value="identification">Ik herken iets of iemand</option>
                    <option value="correction">Er klopt iets niet</option>
                </select>
            </label>
            <label>Je bericht <textarea name="message" required minlength="5" maxlength="2000"></textarea></label>
            <label>Je naam (optioneel) <input type="text" name="submitter_name" maxlength="200"></label>
            <label>Je e-mailadres (optioneel) <input type="email" name="submitter_email" maxlength="255"></label>
            <div class="honeypot" aria-hidden="true">
                <label for="website">Laat dit veld leeg</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>
            <button type="submit">Versturen</button>
        </form>
    </section>

    <script src="/viewer.js" defer></script>
@endsection
