@extends('layouts.app')
@section('title', 'Werklijsten & Curatie - FotoArchief')
@section('content')
<p class="eyebrow"><a href="{{ route('catalogue.index') }}">← Catalogus Dashboard</a></p>
<h1>Werklijsten &amp; Curatiewachtrijen</h1>

@if(session('status'))
    <div class="card" style="border-color: #16a34a; background-color: #f0fdf4;">
        <p>{{ session('status') }}</p>
    </div>
@endif

<section class="card">
    <h2>Slimme curatiewachtrijen (Ontbrekende metadata)</h2>
    <p>Maak direct een werklijst aan om openstaande lacunes in het archief systematisch aan te pakken.</p>
    <div class="grid">
        <div class="card" style="border: 1px solid #e5e7eb;">
            <h3>Datering ontbreekt</h3>
            <p><strong>{{ $stats['missing_dates'] }}</strong> foto’s met onbekende of ontbrekende datering.</p>
            <a href="{{ route('catalogue.worklists.create', ['type' => 'missing_date']) }}" class="button secondary">+ Werklijst dateringen</a>
        </div>
        <div class="card" style="border: 1px solid #e5e7eb;">
            <h3>Rechten ongeverifieerd</h3>
            <p><strong>{{ $stats['missing_rights'] }}</strong> foto’s zonder geverifieerde rechtenstatus.</p>
            <a href="{{ route('catalogue.worklists.create', ['type' => 'missing_rights']) }}" class="button secondary">+ Werklijst rechten</a>
        </div>
        <div class="card" style="border: 1px solid #e5e7eb;">
            <h3>Identificatie ontbreekt</h3>
            <p><strong>{{ $stats['missing_identification'] }}</strong> foto’s zonder gekoppelde personen of locaties.</p>
            <a href="{{ route('catalogue.worklists.create', ['type' => 'missing_identification']) }}" class="button secondary">+ Werklijst identificatie</a>
        </div>
        <div class="card" style="border: 1px solid #e5e7eb;">
            <h3>Herkomst ontbreekt</h3>
            <p><strong>{{ $stats['missing_provenance'] }}</strong> foto’s zonder herkomstbron of schenker.</p>
            <a href="{{ route('catalogue.worklists.create', ['type' => 'missing_provenance']) }}" class="button secondary">+ Werklijst herkomst</a>
        </div>
    </div>
</section>

<section class="card">
    <div class="actions" style="margin-bottom: 1rem;">
        <a href="{{ route('catalogue.worklists.create') }}" class="button">Nieuwe werklijst samenstellen</a>
    </div>

    <h2>Bestaande werklijsten</h2>
    <ul class="asset-list">
        @forelse($worklists as $wl)
            <li>
                <a href="{{ route('catalogue.worklists.show', $wl) }}"><strong>{{ $wl->title }}</strong></a>
                <small>
                    Type: {{ $wl->worklist_type }} · Status: {{ $wl->status }} · Voortgang: {{ $wl->progressPercentage() }}% ({{ $wl->completed_items_count }}/{{ $wl->items_count }})
                    @if($wl->assignedTo) · Toegewezen aan: {{ $wl->assignedTo->name }} @endif
                    · Aangemaakt door: {{ $wl->createdBy->name }}
                </small>
            </li>
        @empty
            <li>Nog geen werklijsten aangemaakt.</li>
        @endforelse
    </ul>
</section>
@endsection
