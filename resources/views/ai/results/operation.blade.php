@extends('layouts.app')
@section('title', 'Foto’s en AI-resultaten - FotoArchief')
@section('content')
    @include('operations._nav')
    <h1>Foto's en AI-resultaten</h1>
    <p>Taak <code>{{ $run->id }}</code> · {{ $run->operation_type }}</p>
    <p>Hier staan de toegankelijke foto's die volgens het auditlog succesvol zijn verwerkt, inclusief eerdere pogingen van deze taak. De link opent de volledige opgeslagen AI-historie van de foto, niet uitsluitend de uitvoer van deze taak.</p>
    <p>Je hoeft geen nieuwe analyse te starten om opgeslagen resultaten te bekijken. Vernieuw deze pagina als de taak nog bezig is.</p>
    <section class="card">
        <h2>Succesvol verwerkte foto's</h2>
        <ul>
            @forelse($assets as $asset)
                <li><a href="{{ route('admin.assets.show', $asset) }}#ai-results">{{ $asset->accession_number }} · {{ $asset->title ?: 'Zonder titel' }} — AI-resultaten bekijken</a></li>
            @empty
                <li>Nog geen toegankelijke, succesvol verwerkte foto's in het auditlog. De taak kan nog bezig zijn, foto's kunnen verwijderd of niet toegankelijk zijn, of een oude taak heeft geen succeslog. Bekijk dan de AI-resultaten rechtstreeks bij de foto.</li>
            @endforelse
        </ul>
        {{ $assets->links() }}
    </section>
    <a href="{{ route('admin.operations.runs.index') }}">Terug naar achtergrondtaken</a>
@endsection
