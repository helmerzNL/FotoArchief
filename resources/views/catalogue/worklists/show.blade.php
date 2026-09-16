@extends('layouts.app')
@section('title', 'Werklijst: ' . $worklist->title . ' - FotoArchief')
@section('content')
<p class="eyebrow"><a href="{{ route('catalogue.worklists.index') }}">← Alle Werklijsten</a></p>
<h1>Werklijst: {{ $worklist->title }}</h1>

@if(session('status'))
    <div class="card" style="border-color: #16a34a; background-color: #f0fdf4;">
        <p>{{ session('status') }}</p>
    </div>
@endif

<section class="card">
    <h2>Voortgang &amp; Details</h2>
    <div style="background-color: #e5e7eb; border-radius: 9999px; height: 1.25rem; overflow: hidden; margin-bottom: 1rem;">
        <div style="background-color: #16a34a; height: 100%; width: {{ $worklist->progressPercentage() }}%;"></div>
    </div>
    <p><strong>Voortgang:</strong> {{ $worklist->progressPercentage() }}% ({{ $worklist->completedCount() }} van {{ $worklist->totalCount() }} afgerond)</p>
    <p><strong>Status:</strong> {{ $worklist->status }} · <strong>Type:</strong> {{ $worklist->worklist_type }}</p>
    @if($worklist->assignedTo)
        <p><strong>Toegewezen aan:</strong> {{ $worklist->assignedTo->name }} ({{ $worklist->assignedTo->email }})</p>
    @endif
    @if($worklist->description)
        <p><strong>Instructies:</strong> {{ $worklist->description }}</p>
    @endif

    <div class="actions" style="margin-top: 1rem;">
        <a href="{{ route('catalogue.worklists.edit', $worklist) }}" class="button secondary">Werklijst bewerken</a>
    </div>
</section>

<section class="card">
    <h2>Curatie-items ({{ $items->count() }})</h2>

    <div class="actions" style="margin-bottom: 1rem;">
        <a href="{{ route('catalogue.worklists.show', $worklist) }}" class="button {{ empty($statusFilter) ? '' : 'secondary' }}">Alle</a>
        <a href="{{ route('catalogue.worklists.show', [$worklist, 'status' => 'pending']) }}" class="button {{ $statusFilter === 'pending' ? '' : 'secondary' }}">Openstaand</a>
        <a href="{{ route('catalogue.worklists.show', [$worklist, 'status' => 'in_progress']) }}" class="button {{ $statusFilter === 'in_progress' ? '' : 'secondary' }}">In behandeling</a>
        <a href="{{ route('catalogue.worklists.show', [$worklist, 'status' => 'completed']) }}" class="button {{ $statusFilter === 'completed' ? '' : 'secondary' }}">Afgerond</a>
    </div>

    <ul class="asset-list">
        @forelse($items as $item)
            @php($asset = $item->asset)
            @php($file = $asset->files->first())
            <li>
                <div style="display: flex; gap: 1rem; align-items: flex-start; width: 100%;">
                    @if($file && isset($file->derivatives['preview300']))
                        <img class="thumbnail" loading="lazy" src="{{ route('admin.assets.media', [$asset, $file, 'preview300']) }}" alt="">
                    @endif
                    <div style="flex: 1;">
                        <a href="{{ route('admin.assets.show', $asset) }}" target="_blank"><strong>{{ $asset->title ?: $asset->accession_number }} ↗</strong></a>
                        <br>
                        <small>
                            {{ $asset->accession_number }} · Status: <strong>{{ $item->status }}</strong>
                            @if($item->completed_at) · Afgerond op {{ $item->completed_at->format('d-m-Y H:i') }} @if($item->completedBy) door {{ $item->completedBy->name }} @endif @endif
                            @if($item->note) · Notitie: {{ $item->note }} @endif
                        </small>

                        <form method="post" action="{{ route('catalogue.worklists.items.update', [$worklist, $item]) }}" style="margin-top: 0.5rem; display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                            @csrf
                            <input type="text" name="note" value="{{ $item->note }}" placeholder="Korte toelichting / curatienotitie" style="width: auto; flex: 1; min-width: 180px;">
                            <button type="submit" name="status" value="in_progress" class="button secondary">In behandeling</button>
                            <button type="submit" name="status" value="completed">Afronden</button>
                            <button type="submit" name="status" value="skipped" class="button secondary">Overslaan</button>
                        </form>
                    </div>
                </div>
            </li>
        @empty
            <li>Geen items binnen deze filterstatus.</li>
        @endforelse
    </ul>
</section>
@endsection
