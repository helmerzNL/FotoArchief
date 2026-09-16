@extends('layouts.app')
@section('title', 'Export - FotoArchief')
@section('content')
<p class="eyebrow">Uitwisseling</p><h1>Export</h1>
<p class="intro">{{ $export->typeLabel() }} &middot; {{ $export->statusLabel() }}</p>
@if($export->status === 'failed' && $export->failure_reason)
    <div class="errors" role="alert"><strong>Export mislukt</strong><p>{{ $export->failure_reason }}</p></div>
@endif
@if($export->isBusy())
    <div class="notice" role="status">De export wordt samengesteld door een worker. Vernieuw deze pagina.</div>
@endif
<section class="card">
    <h2>Gegevens</h2>
    <ul class="asset-list">
        <li>Aantal foto&rsquo;s: {{ $export->asset_count }}</li>
        <li>Grootte: {{ $export->byte_size === null ? 'onbekend' : number_format($export->byte_size / 1024, 1).' KiB' }}</li>
        <li>Controlegetal (SHA-256): <span class="checksum">{{ $export->sha256 ?? 'nog niet berekend' }}</span></li>
        <li>Beschikbaar tot: {{ $export->expires_at?->format('d-m-Y H:i') ?? 'niet van toepassing' }}</li>
        <li>Aantal downloads: {{ $export->download_count }}</li>
    </ul>
    <p>Het bestand staat in private opslag. Er bestaat geen publieke of directe opslag-URL; downloaden kan alleen via een persoonlijke, kortlopende link.</p>
</section>
@if(($export->manifest['skipped'] ?? []) !== [])
<section class="card">
    <h2>Overgeslagen</h2>
    <ul class="asset-list">
    @foreach($export->manifest['skipped'] as $skipped)
        <li>{{ $skipped['accession_number'] }}: {{ $skipped['reason'] }}</li>
    @endforeach
    </ul>
</section>
@endif
@if(($export->manifest['files'] ?? []) !== [])
<section class="card">
    <h2>Inhoud</h2>
    <table>
        <caption>Bestanden in deze export met hun controlegetal</caption>
        <thead><tr><th scope="col">Pad</th><th scope="col">Soort</th><th scope="col">Bytes</th><th scope="col">SHA-256</th></tr></thead>
        <tbody>
        @foreach(array_slice($export->manifest['files'], 0, 200) as $file)
            <tr><td>{{ $file['path'] }}</td><td>{{ $file['kind'] }}</td><td>{{ $file['byte_size'] }}</td><td class="checksum">{{ $file['sha256'] }}</td></tr>
        @endforeach
        </tbody>
    </table>
</section>
@endif
@if($export->isDownloadable())
<section class="card">
    <h2>Downloaden</h2>
    <p>De link is {{ config('exchange.download_ttl_minutes') }} minuten geldig en werkt alleen voor jou. Bij het downloaden wordt opnieuw gecontroleerd of je alle foto&rsquo;s in deze export nog mag inzien.</p>
    <form method="post" action="{{ route('exchange.exports.link', $export) }}">
        @csrf
        <button type="submit">Bestand downloaden</button>
    </form>
</section>
@endif
@if($export->status === 'failed')
<section class="card">
    <h2>Opnieuw proberen</h2>
    <form method="post" action="{{ route('exchange.exports.retry', $export) }}">
        @csrf
        <button class="secondary" type="submit">Export opnieuw samenstellen</button>
    </form>
</section>
@endif
<p><a href="{{ route('exchange.index') }}">Terug naar uitwisseling</a></p>
@endsection