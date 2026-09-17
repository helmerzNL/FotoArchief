@extends('layouts.app')
@section('title', ($asset->title ?: $asset->accession_number).__('catalogue.fragments.application_suffix'))
@section('content')
<a href="{{ route('admin.assets.index') }}">{{ __('catalogue.generated.t_1453c8c37526025e') }}</a>
<h1>{{ $asset->title ?: $asset->accession_number }}</h1>
<p>{{ $asset->accession_number }} {{ __('catalogue.generated.t_5fac28e5672fef1d') }} {{ $asset->lock_version }}</p>
<p><a href="#ai-results">{{ __('catalogue.generated.t_d22d67d7e9ebfa16') }}</a></p>
@php($file = $asset->files->first())
@php($right = $asset->rights->sortByDesc('id')->first())
@if($file && isset($file->derivatives['preview1200']))
    <img class="preview" src="{{ route('admin.assets.media', [$asset, $file, 'preview1200']) }}" alt="Voorbeeld van {{ $asset->title ?: $asset->accession_number }}">
    <p><a href="{{ route('admin.assets.media', [$asset, $file, 'preview2000']) }}">{{ __('catalogue.generated.t_213d129cfee83c16') }}</a></p>
@endif
<section class="card"><h2>Verwerking</h2>
    @foreach($asset->uploads as $upload)
        <p>{{ $upload->original_filename }}: <strong>{{ $upload->status }}</strong> · {{ $upload->attempts }} {{ __('catalogue.fragments.attempts') }}</p>
        @if($upload->failure_reason)<p role="alert">{{ $upload->failure_reason }}</p>@endif
        @canany(['assets.update', 'catalogue.manage', 'users.manage'])<p><a href="{{ route('admin.operations.processing.show', $upload) }}">{{ __('catalogue.generated.t_02d801eb4453bb6e') }} {{ $upload->original_filename }}</a></p>@endcanany
        @can('update', $asset)
            @if($upload->status === 'failed' || ($upload->status === 'running' && $upload->started_at?->lt(now()->subMinutes(4))))
                <form method="post" action="{{ route('admin.assets.retry', [$asset, $upload]) }}">@csrf<button>{{ __('catalogue.generated.t_a69428155b67f899') }}</button></form>
            @endif
        @endcan
    @endforeach
    @if($file)
        <p>Status: <strong>{{ $file->ingest_status }}</strong> {{ __('catalogue.generated.t_ca0369fb4e5a2f74') }} <strong>{{ $file->scanner_status === 'clean' ? 'Schoon volgens ingest-scanner' : 'NIET GESCAND' }}</strong></p>
        <p>{{ $file->pixel_width }} × {{ $file->pixel_height }} {{ __('catalogue.generated.t_98bb76673444cd14') }} {{ $file->media_type }} · {{ number_format($file->byte_size) }} {{ __('catalogue.fragments.bytes') }}</p>
        <p class="checksum">SHA-256: {{ $file->sha256 }}</p>
        <p>{{ __('catalogue.generated.t_e09420bb3e35b28b') }} {{ $file->technical_metadata['orientation'] ?? 'onbekend' }}{{ __('catalogue.generated.t_cafe7f0d435cf7c0') }}</p>
    @else
        <p>{{ __('catalogue.generated.t_f447e280b1df24b2') }}</p>
    @endif
    <a href="{{ route('admin.assets.show', $asset) }}">{{ __('catalogue.generated.t_b64b6b7558474301') }}</a>
    <p>{{ __('catalogue.generated.t_d9ec106a4491f7b6') }}</p>
</section>
<section class="card"><h2>{{ __('catalogue.generated.t_c8e6c9b1a3d8fa52') }}</h2>
    <p>{{ $asset->description ?: 'Nog geen beschrijving.' }}</p>
    <p>{{ __('catalogue.fragments.dating') }}: {{ $asset->date_display ?: $asset->date_precision }} · {{ $asset->date_earliest?->format('Y-m-d') }} — {{ $asset->date_latest?->format('Y-m-d') }}</p>
    <p>Tags: {{ $asset->tags->pluck('name')->join(', ') ?: 'Geen' }}</p>
    <p>{{ __('catalogue.fragments.rights_holder') }}: {{ $right?->rights_holder ?: 'Onbekend' }} · {{ $right?->verification_status ?? 'unverified' }}</p>
    <p>{{ $right?->note }}</p>
</section>
@include('ai.results._photo')
@can('update', $asset)
<section class="card"><h2>{{ __('catalogue.generated.t_0b5aad2002476f41') }}</h2>
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
        <p>{{ __('catalogue.generated.t_1f06d3c1f6dd3929') }}</p>
        <label>Van <input type="date" name="date_earliest" value="{{ old('date_earliest', $asset->date_earliest?->format('Y-m-d')) }}"></label>
        <label>Tot <input type="date" name="date_latest" value="{{ old('date_latest', $asset->date_latest?->format('Y-m-d')) }}"></label>
        <label>{{ __('catalogue.generated.t_77e3cd6e89341a93') }} <input name="date_display" maxlength="255" value="{{ old('date_display', $asset->date_display) }}"></label>
        <label>{{ __('catalogue.generated.t_144ea79e7876c626') }} <input name="tags" maxlength="2000" value="{{ old('tags', $asset->tags->pluck('name')->join(', ')) }}"></label>
        <label>Rechthebbende <input name="rights_holder" maxlength="255" value="{{ old('rights_holder', $right?->rights_holder) }}"></label>
        <label>Rechtencontrole <select name="rights_status">
            @foreach(['unverified'=>'Onbekend / niet geverifieerd','verified'=>'Geverifieerd','disputed'=>'Betwist'] as $key=>$label)
                <option value="{{ $key }}" @selected(old('rights_status', $right?->verification_status ?? 'unverified') === $key)>{{ $label }}</option>
            @endforeach
        </select></label>
        <label>{{ __('catalogue.generated.t_97502a225516e180') }} <textarea name="rights_note" maxlength="10000">{{ old('rights_note', $right?->note) }}</textarea></label>
        <p>{{ __('catalogue.generated.t_ddb6b082550b78f7') }}</p>
        <button>Opslaan</button>
    </form>
</section>
@endcan
<section class="card"><h2>Archiefbewerkingen</h2>
    <p>{{ __('catalogue.generated.t_d75725cecb05bcc9') }}</p>
    <ul class="actions" style="list-style: none; padding: 0;">
        @can('assets.view')
            <li><a class="button secondary" href="{{ route('admin.operations.versions.index', $asset) }}">{{ __('catalogue.generated.t_6d80c9381b112519') }}</a></li>
        @endcan
        @canany(['catalogue.manage', 'users.manage', 'assets.view'])
            <li><a class="button secondary" href="{{ route('admin.operations.ocr.index', ['q' => $asset->accession_number]) }}">{{ __('catalogue.generated.t_f786745fa8f4515d') }}</a></li>
        @endcanany
        @canany(['assets.update', 'catalogue.manage', 'users.manage'])
            <li><a class="button secondary" href="{{ route('admin.operations.runs.index') }}">Achtergrondtaken</a></li>
        @endcanany
    </ul>
    @canany(['catalogue.manage', 'users.manage'])
        <form method="post" action="{{ route('admin.operations.ocr.dispatch', $asset) }}">
            @csrf
            <button class="secondary">{{ __('catalogue.generated.t_be33d4bc26e3fc3a') }}</button>
        </form>
        <p>{{ __('catalogue.generated.t_6e9a4a7327abfeac') }}</p>
        <form method="post" action="{{ route('admin.operations.trash.trash', $asset) }}" onsubmit="return confirm('Deze foto naar de prullenbak verplaatsen? Herstellen kan via Operaties · Prullenbak.');">
            @csrf
            <label for="trash-reason">{{ __('catalogue.generated.t_4d6fd29b27f22db9') }}</label>
            <input id="trash-reason" name="reason" maxlength="1000" required placeholder="{{ __('catalogue.generated.t_8d65165c975026b8') }}">
            <button class="secondary">{{ __('catalogue.generated.t_75b26d698742de62') }}</button>
        </form>
        <p>{{ __('catalogue.generated.t_21ef49971f3377bd') }}</p>
    @endcanany
</section><section class="card"><h2>{{ __('catalogue.generated.t_a7038276a26c2f41') }}</h2>
    <p>{{ __('catalogue.generated.t_1026e1d956549e47') }}</p>
    <ol>@forelse($events as $event)
        <li>{{ $event->created_at }} · {{ $event->event_type }} · {{ $event->actor_user_id ? 'Medewerker '.$event->actor_user_id : 'Worker' }}
            @if($event->details)<details><summary>{{ __('catalogue.generated.t_99e075f8b379f354') }}</summary><pre class="revision">{{ json_encode($event->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details>@endif
        </li>
    @empty<li>{{ __('catalogue.generated.t_d211c35b14ec2a0c') }}</li>@endforelse</ol>
</section>
@endsection
