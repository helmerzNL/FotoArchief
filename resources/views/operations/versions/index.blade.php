@extends('layouts.app')
@section('title', 'Bestandsversies - ' . $asset->title)
@section('content')
    @include('operations._nav')
    <p class="eyebrow"><a href="{{ route('admin.assets.show', $asset) }}">&larr; Terug naar {{ $asset->accession_number }}</a></p>
    <h1>Bestandsversies &amp; Scans</h1>
    <p class="intro">Historisch versieverloop van scans voor dossier <strong>{{ $asset->accession_number }} &ndash; {{ $asset->title }}</strong>. Eerdere originelen en checksums blijven ongewijzigd bewaard.</p>

    {{-- Upload nieuwere / verbeterde scan --}}
    <section class="card" style="margin-bottom: 2rem;">
        <h2>Verbeterde / Nieuwere Scan Uploaden</h2>
        <p>Upload een hogere resolutie of verbeterde restauratiescan. Het vorige origineel blijft permanent bewaard in de versiegeschiedenis.</p>

        <form method="post" action="{{ route('admin.operations.versions.store', $asset) }}" enctype="multipart/form-data" style="margin-top: 1rem;">
            @csrf
            <div style="margin-bottom: 1rem;">
                <label for="file"><strong>Selecteer afbeeldingsbestand (JPEG, PNG, WebP):</strong></label>
                <input type="file" id="file" name="file" required accept="image/jpeg,image/png,image/webp">
            </div>

            <div style="margin-bottom: 1rem;">
                <label for="change_note"><strong>Reden / Toelichting bij deze versie:</strong></label>
                <input type="text" id="change_note" name="change_note" style="width: 100%;" placeholder="Bijv. Nieuwe 1200 DPI scan van glasnegatief i.p.v. afdruk.">
            </div>

            <button type="submit" class="button">Upload Nieuwe Versie</button>
        </form>
    </section>

    {{-- Versieoverzicht tabel --}}
    <section class="card">
        <h2>Versiegeschiedenis</h2>
        @if($versions->isEmpty())
            <p>Nog geen geregistreerde bestandsversies.</p>
        @else
            {{-- Tabellen mogen op een telefoon van 390 px de pagina niet zijwaarts laten schuiven. --}}
<div class="ops-table-scroll" style="overflow-x: auto; max-width: 100%;"><table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
                <thead>
                    <tr style="text-align: left; border-bottom: 2px solid #e5e7eb;">
                        <th style="padding: 0.75rem;">Versie</th>
                        <th style="padding: 0.75rem;">Bestandsnaam</th>
                        <th style="padding: 0.75rem;">Resolutie</th>
                        <th style="padding: 0.75rem;">Checksum (SHA-256)</th>
                        <th style="padding: 0.75rem;">Status</th>
                        <th style="padding: 0.75rem;">Toelichting</th>
                        <th style="padding: 0.75rem;">Acties</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($versions as $v)
                        <tr style="border-bottom: 1px solid #e5e7eb; @if($v->is_current) background-color: #f0fdf4; @endif">
                            <td style="padding: 0.75rem;">
                                <strong>v{{ $v->version_number }}</strong>
                                @if($v->is_current)
                                    <span style="background: #10b981; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.75rem;">Primair</span>
                                @endif
                            </td>
                            <td style="padding: 0.75rem;">{{ $v->file?->original_filename ?? 'Onbekend' }}</td>
                            <td style="padding: 0.75rem;">{{ $v->file ? ($v->file->pixel_width . 'x' . $v->file->pixel_height . ' px') : '-' }}</td>
                            <td style="padding: 0.75rem;"><code style="font-size: 0.75rem;">{{ substr($v->file?->sha256 ?? '', 0, 16) }}...</code></td>
                            <td style="padding: 0.75rem;">{{ $v->file?->ingest_status ?? '-' }}</td>
                            <td style="padding: 0.75rem;">{{ $v->change_note }}</td>
                            <td style="padding: 0.75rem;">
                                @if($v->file)
                                    <div style="display: flex; gap: 0.5rem;">
                                        @if(!$v->is_current)
                                            <form method="post" action="{{ route('admin.operations.versions.setActive', [$asset, $v->file]) }}">
                                                @csrf
                                                <button type="submit" class="secondary" style="padding: 4px 8px; font-size: 0.8rem;">Maak Primair</button>
                                            </form>
                                        @endif
                                        <form method="post" action="{{ route('admin.operations.versions.reprocess', [$asset, $v->file]) }}">
                                            @csrf
                                            <button type="submit" class="secondary" style="padding: 4px 8px; font-size: 0.8rem;">Herbouw Weergaven</button>
                                        </form>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </section>
@endsection
