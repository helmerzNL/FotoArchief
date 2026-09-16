@extends('layouts.app')
@section('title', 'Tag: ' . $tag->name . ' - FotoArchief')
@section('content')
<p class="eyebrow"><a href="{{ route('catalogue.tags.index') }}">← Alle Tags</a></p>
<h1>Tag: {{ $tag->name }}</h1>

@if(session('status'))
    <div class="card" style="border-color: #16a34a; background-color: #f0fdf4;">
        <p>{{ session('status') }}</p>
    </div>
@endif

<section class="card">
    <h2>Details</h2>
    <p><strong>Naam:</strong> {{ $tag->name }}</p>
    <p><strong>Slug:</strong> <code>{{ $tag->slug }}</code></p>
    @if($tag->description)
        <p><strong>Beschrijving:</strong> {{ $tag->description }}</p>
    @endif
    @if($tag->synonyms->isNotEmpty())
        <p><strong>Synoniemen &amp; varianten:</strong> {{ $tag->synonyms->pluck('name')->join(', ') }}</p>
    @endif

    <div class="actions" style="margin-top: 1rem;">
        <a href="{{ route('catalogue.tags.edit', $tag) }}" class="button">Bewerken / Samenvoegen</a>
    </div>
</section>

@if($otherTags->isNotEmpty())
<section class="card">
    <h2>Samenvoegen met andere tag</h2>
    <form method="post" action="{{ route('catalogue.tags.merge', $tag) }}">
        @csrf
        <div class="grid">
            <div>
                <label for="target_tag_id">Doeltag kiezen</label>
                <select id="target_tag_id" name="target_tag_id" required>
                    <option value="">-- Kies doeltag --</option>
                    @foreach($otherTags as $ot)
                        <option value="{{ $ot->id }}">{{ $ot->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="actions">
            <button type="submit" onclick="return confirm('Weet je zeker dat je deze tag wilt samenvoegen?')">Samenvoegen</button>
        </div>
    </form>
</section>
@endif

<section class="card">
    <h2>Gekoppelde foto’s ({{ $assets->count() }})</h2>
    <ul class="asset-list">
        @forelse($assets as $asset)
            @php($file = $asset->files->first())
            <li>
                @if($file && isset($file->derivatives['preview300']))
                    <img class="thumbnail" loading="lazy" src="{{ route('admin.assets.media', [$asset, $file, 'preview300']) }}" alt="">
                @endif
                <a href="{{ route('admin.assets.show', $asset) }}">{{ $asset->title ?: $asset->accession_number }}</a>
                <small>
                    {{ $asset->accession_number }} · {{ $asset->catalogue_status }}
                    @if($asset->date_display) · {{ $asset->date_display }} @endif
                </small>
            </li>
        @empty
            <li>Geen foto’s direct aan deze tag gekoppeld binnen jouw toegang.</li>
        @endforelse
    </ul>
</section>
@endsection
