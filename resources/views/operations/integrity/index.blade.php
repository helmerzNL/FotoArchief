@extends('layouts.app')
@section('title', 'Bestandsintegriteit - Vistora Operaties')
@section('content')
    @include('operations._nav')
    <p class="eyebrow">{{ __('operations.generated.t_e160841c8fc79855') }}</p>
    <h1>{{ __('operations.generated.t_4f0104035d6f7f41') }}</h1>
    <p class="intro">{{ __('operations.generated.t_ff5a26245a7773ca') }}</p>

    {{-- Overzicht kaarten --}}
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
        <div class="card" style="text-align: center;">
            <div style="font-size: 1.75rem; font-weight: bold;">{{ $summary['total_files'] }}</div>
            <div style="font-size: 0.875rem; color: #6b7280;">Archiefbestanden</div>
        </div>
        <div class="card" style="text-align: center;">
            <div style="font-size: 1.75rem; font-weight: bold; color: #059669;">{{ $summary['ok'] }}</div>
            <div style="font-size: 0.875rem; color: #6b7280;">{{ __('operations.generated.t_27025e00d9c3ac84') }}</div>
        </div>
        <div class="card" style="text-align: center;">
            <div style="font-size: 1.75rem; font-weight: bold; color: #dc2626;">{{ $summary['missing_original'] }}</div>
            <div style="font-size: 0.875rem; color: #6b7280;">{{ __('operations.generated.t_22f34e9e8956397b') }}</div>
        </div>
        <div class="card" style="text-align: center;">
            <div style="font-size: 1.75rem; font-weight: bold; color: #dc2626;">{{ $summary['corrupt_checksum'] }}</div>
            <div style="font-size: 0.875rem; color: #6b7280;">{{ __('operations.generated.t_8899a19ee5efa9f7') }}</div>
        </div>
        <div class="card" style="text-align: center;">
            <div style="font-size: 1.75rem; font-weight: bold; color: #d97706;">{{ $summary['missing_derivative'] }}</div>
            <div style="font-size: 0.875rem; color: #6b7280;">{{ __('operations.generated.t_8651b079cf8241ed') }}</div>
        </div>
    </div>

    {{-- Actieknoppen --}}
    <div style="display: flex; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap;">
        <form method="post" action="{{ route('admin.operations.integrity.run') }}">
            @csrf
            <button type="submit" class="button">{{ __('operations.generated.t_e853ca2e0a665210') }}</button>
        </form>

        @if($summary['missing_derivative'] > 0)
            <form method="post" action="{{ route('admin.operations.integrity.rebuildAll') }}">
                @csrf
                <button type="submit" class="button" style="background-color: #d97706;">{{ __('operations.generated.t_06d3b6ef409181c1') }}{{ $summary['missing_derivative'] }})</button>
            </form>
        @endif
    </div>

    {{-- Aandachtspunten tabel --}}
    <section class="card">
        <h2>{{ __('operations.generated.t_15b7da39c77a3e72') }}</h2>
        @if($issues->isEmpty())
            <div style="padding: 1rem 0; color: #059669;">
                <strong>{{ __('operations.generated.t_b4cc8b4f8beaa982') }}</strong> {{ __('operations.generated.t_2e8dc9859dc7dcea') }}
            </div>
        @else
            {{-- Tabellen mogen op een telefoon van 390 px de pagina niet zijwaarts laten schuiven. --}}
<div class="ops-table-scroll" style="overflow-x: auto; max-width: 100%;"><table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
                <thead>
                    <tr style="text-align: left; border-bottom: 2px solid #e5e7eb;">
                        <th style="padding: 0.75rem;">{{ __('operations.generated.t_8f85c97ef7481b34') }}</th>
                        <th style="padding: 0.75rem;">Probleemtype</th>
                        <th style="padding: 0.75rem;">{{ __('operations.generated.t_3ca617c0488da9c0') }}</th>
                        <th style="padding: 0.75rem;">{{ __('operations.generated.t_d9221de7754afb07') }}</th>
                        <th style="padding: 0.75rem;">Details</th>
                        <th style="padding: 0.75rem;">Actie</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($issues as $issue)
                        <tr style="border-bottom: 1px solid #e5e7eb;">
                            <td style="padding: 0.75rem;">
                                @if($issue->asset)
                                    <a href="{{ route('admin.assets.show', $issue->asset) }}"><strong>{{ $issue->asset->accession_number }}</strong></a><br>
                                @endif
                                <span style="font-size: 0.875rem; color: #4b5563;">{{ $issue->file?->original_filename ?? 'Onbekend' }}</span>
                            </td>
                            <td style="padding: 0.75rem;">
                                @if($issue->status === 'missing_original')
                                    <span style="color: #dc2626; font-weight: bold;">{{ __('operations.generated.t_22f34e9e8956397b') }}</span>
                                @elseif($issue->status === 'corrupt_checksum')
                                    <span style="color: #dc2626; font-weight: bold;">{{ __('operations.generated.t_a8e33847e8e9ef6c') }}</span>
                                @elseif($issue->status === 'missing_derivative')
                                    <span style="color: #d97706; font-weight: bold;">{{ __('operations.generated.t_8651b079cf8241ed') }}</span>
                                @else
                                    <span>{{ $issue->status }}</span>
                                @endif
                            </td>
                            <td style="padding: 0.75rem;"><code style="font-size: 0.75rem;">{{ substr($issue->expected_sha256 ?? '', 0, 12) }}...</code></td>
                            <td style="padding: 0.75rem;">
                                @if($issue->actual_sha256)
                                    <code style="font-size: 0.75rem; color: #dc2626;">{{ substr($issue->actual_sha256, 0, 12) }}...</code>
                                @else
                                    -
                                @endif
                            </td>
                            <td style="padding: 0.75rem; font-size: 0.875rem;">{{ $issue->details['message'] ?? '-' }}</td>
                            <td style="padding: 0.75rem;">
                                @if($issue->status === 'missing_derivative' && $issue->file)
                                    <form method="post" action="{{ route('admin.operations.integrity.rebuild', $issue->file) }}">
                                        @csrf
                                        <button type="submit" class="secondary" style="padding: 4px 8px; font-size: 0.8rem;">{{ __('operations.generated.t_09e71fc8ab17eeda') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>

            <div style="margin-top: 1.5rem;">
                {{ $issues->links() }}
            </div>
        @endif
    </section>
    @include('operations.runs._panel', ['runs' => $runs])
@endsection
