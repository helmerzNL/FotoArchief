@extends('layouts.app')
@section('title', 'Bestandsversies - ' . $asset->title)
@section('content')
    @include('operations._nav')
    <p class="eyebrow"><a href="{{ route('admin.assets.show', $asset) }}">{{ __('operations.generated.t_9bcb9956780b083d') }} {{ $asset->accession_number }}</a></p>
    <h1>{{ __('operations.generated.t_4e27880c76064f3b') }}</h1>
    <p class="intro">{{ __('operations.generated.t_fd4a65994d243fe3') }} <strong>{{ $asset->accession_number }} &ndash; {{ $asset->title }}</strong>{{ __('operations.generated.t_0d001c2f2ce2a47b') }}</p>

    {{-- Upload nieuwere / verbeterde scan --}}
    <section class="card" style="margin-bottom: 2rem;">
        <h2>{{ __('operations.generated.t_b1acc74e09b7fb20') }}</h2>
        <p>{{ __('operations.generated.t_145b708a355a6295') }}</p>

        <form method="post" action="{{ route('admin.operations.versions.store', $asset) }}" enctype="multipart/form-data" style="margin-top: 1rem;">
            @csrf
            <div style="margin-bottom: 1rem;">
                <label for="file"><strong>{{ __('operations.generated.t_d63af613217e18c2') }}</strong></label>
                <input type="file" id="file" name="file" required accept="image/jpeg,image/png,image/webp">
            </div>

            <div style="margin-bottom: 1rem;">
                <label for="change_note"><strong>{{ __('operations.generated.t_4d04f413c952f931') }}</strong></label>
                <input type="text" id="change_note" name="change_note" style="width: 100%;" placeholder="{{ __('operations.generated.t_7f9b30d2b48326c4') }}">
            </div>

            <button type="submit" class="button">{{ __('operations.generated.t_dec49d4527649f3d') }}</button>
        </form>
    </section>

    {{-- Versieoverzicht tabel --}}
    <section class="card">
        <h2>Versiegeschiedenis</h2>
        @if($versions->isEmpty())
            <p>{{ __('operations.generated.t_5533e60e53cdc48c') }}</p>
        @else
            {{-- Tabellen mogen op een telefoon van 390 px de pagina niet zijwaarts laten schuiven. --}}
<div class="ops-table-scroll" style="overflow-x: auto; max-width: 100%;"><table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
                <thead>
                    <tr style="text-align: left; border-bottom: 2px solid #e5e7eb;">
                        <th style="padding: 0.75rem;">Versie</th>
                        <th style="padding: 0.75rem;">Bestandsnaam</th>
                        <th style="padding: 0.75rem;">Resolutie</th>
                        <th style="padding: 0.75rem;">{{ __('operations.generated.t_f83575ac7a532566') }}</th>
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
                                                <button type="submit" class="secondary" style="padding: 4px 8px; font-size: 0.8rem;">{{ __('operations.generated.t_ded73e10a5cb19db') }}</button>
                                            </form>
                                        @endif
                                        <form method="post" action="{{ route('admin.operations.versions.reprocess', [$asset, $v->file]) }}">
                                            @csrf
                                            <button type="submit" class="secondary" style="padding: 4px 8px; font-size: 0.8rem;">{{ __('operations.generated.t_09e71fc8ab17eeda') }}</button>
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
