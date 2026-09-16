@extends('layouts.app')

@section('title', 'Archiefbewerkingen')

@section('content')
    <h1>Archiefbewerkingen</h1>
    <p>Kies een bewerking. Je ziet hier alleen de onderdelen waarvoor je rechten hebt.</p>

    @include('operations._nav')

    <section class="card">
        <h2>Wat hoort waar</h2>
        <dl>
            <dt>Duplicaten</dt>
            <dd>Een afgewezen dubbele scan koppelen aan het bestaande dossier, zonder een tweede origineel toe te voegen.</dd>
            <dt>Bestandsversies</dt>
            <dd>Een betere scan toevoegen naast het origineel, en kiezen welke versie het archief toont.</dd>
            <dt>Verwerking</dt>
            <dd>Uploads die nog lopen, vastliepen of opnieuw geprobeerd moeten worden.</dd>
            <dt>Integriteit</dt>
            <dd>Controleren of elk bestand er nog is en of de checksum klopt.</dd>
            <dt>Opslagmigratie</dt>
            <dd>Bestanden gecontroleerd naar een andere schijf kopiëren; de bron blijft staan tot alles is geverifieerd.</dd>
            <dt>Prullenbak</dt>
            <dd>Verwijderde dossiers terugzetten of definitief opruimen.</dd>
            <dt>OCR-tekst</dt>
            <dd>Machinaal gelezen tekst bekijken, corrigeren en doorzoeken.</dd>
            <dt>AI-instellingen</dt>
            <dd>Optionele beeldanalyse en semantisch zoeken expliciet aanzetten, begrenzen of met de noodstop blokkeren.</dd>
            <dt>Achtergrondtaken</dt>
            <dd>De voortgang, fouten en herstart van zware bewerkingen.</dd>
        </dl>
    </section>
@endsection
