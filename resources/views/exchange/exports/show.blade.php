@extends('layouts.app')
@section('title', 'Export - FotoArchief')
@section('content')
<p class="eyebrow">Uitwisseling</p><h1>Export</h1>
<p class="intro">{{ $export->typeLabel() }} &middot; {{ $export->statusLabel() }}</p>
@if($export->status === 'failed' && $export->failure_reason)
    <div class="errors" role="alert"><strong>{{ __('exchange.generated.t_64f5bcf170584aba') }}</strong><p>{{ $export->failure_reason }}</p></div>
@endif
@if($export->isBusy())
    <div class="notice" role="status">{{ __('exchange.generated.t_35e676d0c367b18d') }}</div>
@endif
<section class="card">
    <h2>Gegevens</h2>
    <ul class="asset-list">
        <li>{{ __('exchange.generated.t_f6f44f8931ebabb0') }} {{ $export->asset_count }}</li>
        <li>Grootte: {{ $export->byte_size === null ? 'onbekend' : number_format($export->byte_size / 1024, 1).' KiB' }}</li>
        <li>{{ __('exchange.generated.t_fa25cee5b6c25aa3') }} <span class="checksum">{{ $export->sha256 ?? 'nog niet berekend' }}</span></li>
        <li>{{ __('exchange.generated.t_ba4efa02a4ac38e8') }} {{ $export->expires_at?->format('d-m-Y H:i') ?? 'niet van toepassing' }}</li>
        <li>{{ __('exchange.generated.t_5a1567ca4894049a') }} {{ $export->download_count }}</li>
    </ul>
    <p>{{ __('exchange.generated.t_e82a2edb679e5b2d') }}</p>
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
        <caption>{{ __('exchange.generated.t_604182d90c8d83f3') }}</caption>
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
    <p>{{ __('exchange.generated.t_c890fb7126fdc21b') }} {{ config('exchange.download_ttl_minutes') }} {{ __('exchange.generated.t_289596075ab81f79') }}</p>
    <form method="post" action="{{ route('exchange.exports.link', $export) }}">
        @csrf
        <button type="submit">{{ __('exchange.generated.t_dce8cab4e4ca0f1b') }}</button>
    </form>
</section>
@endif
@if($export->status === 'failed')
<section class="card">
    <h2>{{ __('exchange.generated.t_e05ea9918232c0b5') }}</h2>
    <form method="post" action="{{ route('exchange.exports.retry', $export) }}">
        @csrf
        <button class="secondary" type="submit">{{ __('exchange.generated.t_ee32917364b0aad5') }}</button>
    </form>
</section>
@endif
<p><a href="{{ route('exchange.index') }}">{{ __('exchange.generated.t_a37b7b9e1dab31cf') }}</a></p>
@endsection