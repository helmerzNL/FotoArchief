@extends('layouts.app')
@section('title', 'Prullenbak & Bewaartermijn - Vistora Operaties')
@section('content')
    @include('operations._nav')
    <p class="eyebrow">{{ __('operations.generated.t_8a67130f2c962a7b') }}</p>
    <h1>{{ __('operations.generated.t_e0399beb1a620a16') }}</h1>
    <p class="intro">{{ __('operations.generated.t_2858af285b10df1b') }}</p>

    {{-- Overzicht cards --}}
    <div class="grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
        <div class="card">
            <h3>{{ __('operations.generated.t_74276349718c2e7d') }}</h3>
            <p style="font-size: 2rem; font-weight: bold; color: #d97706; margin: 0.5rem 0;">{{ $summary['trashed_count'] }}</p>
            <p style="font-size: 0.875rem; color: #4b5563;">{{ __('operations.generated.t_6372d61f13976e16') }}</p>
        </div>

        <div class="card">
            <h3>{{ __('operations.generated.t_e4b514c23d7e0bd0') }}</h3>
            <p style="font-size: 2rem; font-weight: bold; color: #dc2626; margin: 0.5rem 0;">{{ $summary['expired_count'] }}</p>
            <p style="font-size: 0.875rem; color: #4b5563;">{{ __('operations.generated.t_152216f6cc5e9382') }} {{ $summary['retention_days'] }} dagen</p>
        </div>

        <div class="card">
            <h3>Wees-quarantaine</h3>
            <p style="font-size: 2rem; font-weight: bold; color: #4b5563; margin: 0.5rem 0;">{{ $summary['orphan_uploads_count'] }}</p>
            <p style="font-size: 0.875rem; color: #4b5563;">{{ __('operations.generated.t_4838f14e7bdbae3d') }}</p>
        </div>
    </div>

    {{-- Beheeracties --}}
    <div style="display: flex; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap;">
        <form method="post" action="{{ route('admin.operations.trash.purgeExpired') }}">
            @csrf
            <input type="hidden" name="retention_days" value="{{ $summary['retention_days'] }}">
            <button type="submit" class="secondary" style="color: #dc2626;" onclick="return confirm('Weet je zeker dat je alle verlopen items ouder dan {{ $summary['retention_days'] }} dagen definitief wilt verwijderen?');">
                {{ __('operations.generated.t_353fed0e36ea7688') }}{{ $summary['expired_count'] }})
            </button>
        </form>

        <form method="post" action="{{ route('admin.operations.trash.cleanupOrphans') }}">
            @csrf
            <button type="submit" class="secondary" onclick="return confirm('Weet je zeker dat je alle wees-quarantainebestanden wilt opschonen?');">
                {{ __('operations.generated.t_bc55e94416f93b55') }}{{ $summary['orphan_uploads_count'] }})
            </button>
        </form>
    </div>

    {{-- Prullenbak items --}}
    <section class="card" style="margin-bottom: 2rem;">
        <h2>{{ __('operations.generated.t_3e7f2266e2f1cfd5') }}</h2>
        @if($trashedAssets->isEmpty())
            <p>{{ __('operations.generated.t_a8d3df9790138210') }}</p>
        @else
            {{-- Tabellen mogen op een telefoon van 390 px de pagina niet zijwaarts laten schuiven. --}}
<div class="ops-table-scroll" style="overflow-x: auto; max-width: 100%;"><table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
                <thead>
                    <tr style="text-align: left; border-bottom: 2px solid #e5e7eb;">
                        <th style="padding: 0.75rem;">Aanwinstnr</th>
                        <th style="padding: 0.75rem;">Titel</th>
                        <th style="padding: 0.75rem;">{{ __('operations.generated.t_2afd5fba8c765d1d') }}</th>
                        <th style="padding: 0.75rem;">{{ __('operations.generated.t_5fe1e753674d13a3') }}</th>
                        <th style="padding: 0.75rem;">Reden</th>
                        <th style="padding: 0.75rem;">Acties</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($trashedAssets as $asset)
                        <tr style="border-bottom: 1px solid #e5e7eb;">
                            <td style="padding: 0.75rem;"><strong>{{ $asset->accession_number }}</strong></td>
                            <td style="padding: 0.75rem;">{{ $asset->title }}</td>
                            <td style="padding: 0.75rem; font-size: 0.875rem;">{{ $asset->deleted_at?->format('d-m-Y H:i') }}</td>
                            <td style="padding: 0.75rem; font-size: 0.875rem;">{{ $asset->deletedBy?->name ?? 'Onbekend' }}</td>
                            <td style="padding: 0.75rem; font-size: 0.875rem; color: #4b5563;">{{ $asset->deletion_reason ?? '-' }}</td>
                            <td style="padding: 0.75rem;">
                                <div style="display: flex; gap: 0.5rem;">
                                    <form method="post" action="{{ route('admin.operations.trash.restore', $asset->id) }}">
                                        @csrf
                                        <button type="submit" class="button" style="padding: 4px 8px; font-size: 0.8rem;">Herstellen</button>
                                    </form>

                                    <form method="post" action="{{ route('admin.operations.trash.purge', $asset->id) }}" onsubmit="return prompt('Typ BEVESTIG om dit item definitief en onherroepelijk te verwijderen:') === 'BEVESTIG';">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="reason" value="Handmatige definitieve verwijdering via Prullenbak UI">
                                        <input type="hidden" name="confirm_purge" value="1">
                                        <button type="submit" class="secondary" style="padding: 4px 8px; font-size: 0.8rem; color: #dc2626;">{{ __('operations.generated.t_34dc19a47b213103') }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>

            <div style="margin-top: 1rem;">
                {{ $trashedAssets->links() }}
            </div>
        @endif
    </section>

    {{-- Audit log van definitieve purges --}}
    <section class="card">
        <h2>{{ __('operations.generated.t_bff7ebd1cb0e211d') }}</h2>
        @if($recentPurges->isEmpty())
            <p>{{ __('operations.generated.t_275590b294851df4') }}</p>
        @else
            {{-- Tabellen mogen op een telefoon van 390 px de pagina niet zijwaarts laten schuiven. --}}
<div class="ops-table-scroll" style="overflow-x: auto; max-width: 100%;"><table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
                <thead>
                    <tr style="text-align: left; border-bottom: 2px solid #e5e7eb;">
                        <th style="padding: 0.75rem;">Datum</th>
                        <th style="padding: 0.75rem;">Aanwinstnr</th>
                        <th style="padding: 0.75rem;">Titel</th>
                        <th style="padding: 0.75rem;">{{ __('operations.generated.t_5fe1e753674d13a3') }}</th>
                        <th style="padding: 0.75rem;">Reden</th>
                        <th style="padding: 0.75rem;">Bestanden</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentPurges as $log)
                        <tr style="border-bottom: 1px solid #e5e7eb; font-size: 0.875rem;">
                            <td style="padding: 0.75rem;">{{ $log->created_at?->format('d-m-Y H:i:s') }}</td>
                            <td style="padding: 0.75rem;"><strong>{{ $log->accession_number }}</strong></td>
                            <td style="padding: 0.75rem;">{{ $log->title }}</td>
                            <td style="padding: 0.75rem;">{{ $log->purgedBy?->name ?? 'Systeem' }}</td>
                            <td style="padding: 0.75rem; color: #4b5563;">{{ $log->reason }}</td>
                            <td style="padding: 0.75rem;">{{ $log->deleted_files_count }} bestanden</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </section>
    @include('operations.runs._panel', ['runs' => $runs])
@endsection
