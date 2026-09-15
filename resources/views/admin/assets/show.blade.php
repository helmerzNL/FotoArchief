@extends('layouts.app')
@section('title', ($asset->title ?: $asset->accession_number).' - FotoArchief')
@section('content')
<a href="{{ route('admin.assets.index') }}">Terug naar alle foto’s</a>
<h1>{{ $asset->title ?: $asset->accession_number }}</h1>
<p>{{ $asset->accession_number }} · Concept / privé · Revisie {{ $asset->lock_version }}</p>
@php($file = $asset->files->first())
@php($right = $asset->rights->sortByDesc('id')->first())
@if($file && isset($file->derivatives['preview1200']))
    <img class="preview" src="{{ route('admin.assets.media', [$asset, $file, 'preview1200']) }}" alt="Voorbeeld van {{ $asset->title ?: $asset->accession_number }}">
    <p><a href="{{ route('admin.assets.media', [$asset, $file, 'preview2000']) }}">Groot privévoorbeeld (maximaal 2000 px)</a></p>
@endif
<section class="card"><h2>Verwerking</h2>
    @foreach($asset->uploads as $upload)
        <p>{{ $upload->original_filename }}: <strong>{{ $upload->status }}</strong> · {{ $upload->attempts }} poging(en)</p>
        @if($upload->failure_reason)<p role="alert">{{ $upload->failure_reason }}</p>@endif
        @can('update', $asset)
            @if($upload->status === 'failed' || ($upload->status === 'running' && $upload->started_at?->lt(now()->subMinutes(4))))
                <form method="post" action="{{ route('admin.assets.retry', [$asset, $upload]) }}">@csrf<button>Verwerking opnieuw proberen</button></form>
            @endif
        @endcan
    @endforeach
    @if($file)
        <p>Status: <strong>{{ $file->ingest_status }}</strong> · Malwarecontrole: <strong>{{ $file->scanner_status === 'clean' ? 'Schoon volgens ingest-scanner' : 'NIET GESCAND' }}</strong></p>
        <p>{{ $file->pixel_width }} × {{ $file->pixel_height }} px · {{ $file->media_type }} · {{ number_format($file->byte_size) }} bytes</p>
        <p class="checksum">SHA-256: {{ $file->sha256 }}</p>
        <p>Oriëntatie: {{ $file->technical_metadata['orientation'] ?? 'onbekend' }}. EXIF en GPS worden niet overgenomen in voorbeelden. Het origineel blijft ongewijzigd in private opslag.</p>
    @else
        <p>Nog geen verwerkt voorbeeld beschikbaar. Voor queued/running: wacht op de worker en vernieuw deze pagina.</p>
    @endif
    <a href="{{ route('admin.assets.show', $asset) }}">Status vernieuwen</a>
    <p>Er is geen automatische publicatie. Ook een schone scan geeft geen publicatietoestemming.</p>
</section>
<section class="card"><h2>Beschrijving en rechten</h2>
    <p>{{ $asset->description ?: 'Nog geen beschrijving.' }}</p>
    <p>Datering: {{ $asset->date_display ?: $asset->date_precision }} · {{ $asset->date_earliest?->format('Y-m-d') }} — {{ $asset->date_latest?->format('Y-m-d') }}</p>
    <p>Tags: {{ $asset->tags->pluck('name')->join(', ') ?: 'Geen' }}</p>
    <p>Rechthebbende: {{ $right?->rights_holder ?: 'Onbekend' }} · {{ $right?->verification_status ?? 'unverified' }}</p>
    <p>{{ $right?->note }}</p>
</section>
@can('update', $asset)
<section class="card"><h2>Metadata bewerken</h2>
    <form method="post" action="{{ route('admin.assets.update', $asset) }}">
        @csrf @method('PUT')
        <input type="hidden" name="lock_version" value="{{ old('lock_version', $asset->lock_version) }}">
        <label>Titel <input name="title" value="{{ old('title', $asset->title) }}" maxlength="255" required></label>
        <label>Beschrijving <textarea name="description" maxlength="10000">{{ old('description', $asset->description) }}</textarea></label>
        <label>Datering <select name="date_precision">
            @foreach(['unknown'=>'Onbekend','exact'=>'Exact','circa'=>'Circa','year'=>'Jaar','range'=>'Bereik','before'=>'Vóór','after'=>'Na','decade'=>'Decennium'] as $key=>$label)
                <option value="{{ $key }}" @selected(old('date_precision', $asset->date_precision) === $key)>{{ $label }}</option>
            @endforeach
        </select></label>
        <p>Onbekend: beide datums leeg. Exact: begindatum. Vóór: alleen einddatum. Na: alleen begindatum. Bereik: beide datums. Jaar/decennium: kies een begindatum; deze wordt opgeslagen als het volledige jaar/decennium. Circa kan een onzekerheidsbereik bevatten.</p>
        <label>Van <input type="date" name="date_earliest" value="{{ old('date_earliest', $asset->date_earliest?->format('Y-m-d')) }}"></label>
        <label>Tot <input type="date" name="date_latest" value="{{ old('date_latest', $asset->date_latest?->format('Y-m-d')) }}"></label>
        <label>Weergave datering <input name="date_display" maxlength="255" value="{{ old('date_display', $asset->date_display) }}"></label>
        <label>Tags (komma-gescheiden, maximaal 20) <input name="tags" maxlength="2000" value="{{ old('tags', $asset->tags->pluck('name')->join(', ')) }}"></label>
        <label>Rechthebbende <input name="rights_holder" maxlength="255" value="{{ old('rights_holder', $right?->rights_holder) }}"></label>
        <label>Rechtencontrole <select name="rights_status">
            @foreach(['unverified'=>'Onbekend / niet geverifieerd','verified'=>'Geverifieerd','disputed'=>'Betwist'] as $key=>$label)
                <option value="{{ $key }}" @selected(old('rights_status', $right?->verification_status ?? 'unverified') === $key)>{{ $label }}</option>
            @endforeach
        </select></label>
        <label>Rechtennotitie en bewijs <textarea name="rights_note" maxlength="10000">{{ old('rights_note', $right?->note) }}</textarea></label>
        <p>Rechtencontrole is documentatie, geen toestemming voor openbare publicatie.</p>
        <button>Opslaan</button>
    </form>
</section>
@endcan
<section class="card"><h2>Wijzigings- en verwerkingshistorie</h2>
    <p>Laatste 50 gebeurtenissen, nieuwste eerst. Alle revisies blijven in de database bewaard.</p>
    <ol>@forelse($events as $event)
        <li>{{ $event->created_at }} · {{ $event->event_type }} · {{ $event->actor_user_id ? 'Medewerker '.$event->actor_user_id : 'Worker' }}
            @if($event->details)<details><summary>Details / revisie vergelijken</summary><pre class="revision">{{ json_encode($event->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details>@endif
        </li>
    @empty<li>Nog geen gebeurtenissen.</li>@endforelse</ol>
</section>
@endsection
