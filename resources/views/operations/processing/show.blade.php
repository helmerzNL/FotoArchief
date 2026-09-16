@extends('layouts.app')
@section('title', 'Taak Details - ' . $upload->original_filename)
@section('content')
    <p class="eyebrow"><a href="{{ route('admin.operations.processing.index') }}">&larr; Terug naar Verwerkingscentrum</a></p>
    <h1>Verwerkingstaak Details</h1>
    <p class="intro">Gedetailleerde diagnostische gegevens voor uploadtaak <code>{{ $upload->id }}</code>.</p>

    <div class="grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
        <section class="card">
            <h2>Taakstatus &amp; Parameters</h2>
            <p><strong>Originele bestandsnaam:</strong> {{ $upload->original_filename }}</p>
            <p><strong>Grootte:</strong> {{ number_format($upload->byte_size / 1024, 1) }} KB ({{ number_format($upload->byte_size) }} bytes)</p>
            <p><strong>Status:</strong> <strong>{{ $upload->status }}</strong></p>
            <p><strong>Aantal pogingen:</strong> {{ $upload->attempts }}</p>
            <p><strong>Opslagschijf / sleutel:</strong> <code>{{ $upload->storage_disk }}:{{ $upload->storage_key }}</code></p>
            <p><strong>Geüpload door:</strong> {{ $upload->uploadedBy?->name ?? 'Onbekend' }}</p>
            <p><strong>Aangemaakt:</strong> {{ $upload->created_at?->format('d-m-Y H:i:s') }}</p>
            <p><strong>Gestart op:</strong> {{ $upload->started_at ? $upload->started_at->format('d-m-Y H:i:s') : '-' }}</p>
        </section>

        <section class="card">
            <h2>Foutdiagnose &amp; Acties</h2>
            @if($upload->failure_reason)
                <div style="background: #fef2f2; border-left: 4px solid #ef4444; padding: 1rem; margin-bottom: 1.5rem;">
                    <strong>Foutmelding:</strong>
                    <p style="color: #991b1b; margin-top: 0.25rem;">{{ $upload->failure_reason }}</p>
                </div>
            @else
                <p style="color: #059669;">Geen actieve foutmeldingen geregistreerd voor deze taak.</p>
            @endif

            <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                @if(in_array($upload->status, ['failed', 'rejected']) || ($upload->status === 'running' && $upload->started_at?->lt(now()->subMinutes(4))))
                    <form method="post" action="{{ route('admin.operations.processing.retry', $upload) }}">
                        @csrf
                        <button type="submit" class="button">Herstart Verwerking</button>
                    </form>
                @endif
                @if($upload->status === 'running')
                    <form method="post" action="{{ route('admin.operations.processing.cancel', $upload) }}">
                        @csrf
                        <button type="submit" class="secondary" style="color: #dc2626;">Annuleer Taak</button>
                    </form>
                @endif
            </div>
        </section>
    </div>
@endsection
