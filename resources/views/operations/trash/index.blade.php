@extends('layouts.app')
@section('title', 'Prullenbak & Bewaartermijn - FotoArchief Operaties')
@section('content')
    @include('operations._nav')
    <p class="eyebrow">Operaties &middot; Archiefbeheer</p>
    <h1>Prullenbak &amp; Bewaartermijn</h1>
    <p class="intro">Beheer herstelbare verwijderingen, bewaartermijnen en definitieve opschoning van archief- en weesbestanden.</p>

    {{-- Overzicht cards --}}
    <div class="grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
        <div class="card">
            <h3>Items in Prullenbak</h3>
            <p style="font-size: 2rem; font-weight: bold; color: #d97706; margin: 0.5rem 0;">{{ $summary['trashed_count'] }}</p>
            <p style="font-size: 0.875rem; color: #4b5563;">Herstelbaar door archivarissen</p>
        </div>

        <div class="card">
            <h3>Bewaartermijn Verlopen</h3>
            <p style="font-size: 2rem; font-weight: bold; color: #dc2626; margin: 0.5rem 0;">{{ $summary['expired_count'] }}</p>
            <p style="font-size: 0.875rem; color: #4b5563;">Ouder dan {{ $summary['retention_days'] }} dagen</p>
        </div>

        <div class="card">
            <h3>Wees-quarantaine</h3>
            <p style="font-size: 2rem; font-weight: bold; color: #4b5563; margin: 0.5rem 0;">{{ $summary['orphan_uploads_count'] }}</p>
            <p style="font-size: 0.875rem; color: #4b5563;">Mislukte/geannuleerde uploads</p>
        </div>
    </div>

    {{-- Beheeracties --}}
    <div style="display: flex; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap;">
        <form method="post" action="{{ route('admin.operations.trash.purgeExpired') }}">
            @csrf
            <input type="hidden" name="retention_days" value="{{ $summary['retention_days'] }}">
            <button type="submit" class="secondary" style="color: #dc2626;" onclick="return confirm('Weet je zeker dat je alle verlopen items ouder dan {{ $summary['retention_days'] }} dagen definitief wilt verwijderen?');">
                Verwijder Verlopen Prullenbak ({{ $summary['expired_count'] }})
            </button>
        </form>

        <form method="post" action="{{ route('admin.operations.trash.cleanupOrphans') }}">
            @csrf
            <button type="submit" class="secondary" onclick="return confirm('Weet je zeker dat je alle wees-quarantainebestanden wilt opschonen?');">
                Ruim Wees-quarantaine Op ({{ $summary['orphan_uploads_count'] }})
            </button>
        </form>
    </div>

    {{-- Prullenbak items --}}
    <section class="card" style="margin-bottom: 2rem;">
        <h2>Verwijderde Archiefitems</h2>
        @if($trashedAssets->isEmpty())
            <p>De prullenbak is leeg.</p>
        @else
            {{-- Tabellen mogen op een telefoon van 390 px de pagina niet zijwaarts laten schuiven. --}}
<div class="ops-table-scroll" style="overflow-x: auto; max-width: 100%;"><table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
                <thead>
                    <tr style="text-align: left; border-bottom: 2px solid #e5e7eb;">
                        <th style="padding: 0.75rem;">Aanwinstnr</th>
                        <th style="padding: 0.75rem;">Titel</th>
                        <th style="padding: 0.75rem;">Verwijderd op</th>
                        <th style="padding: 0.75rem;">Verwijderd door</th>
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
                                        <button type="submit" class="secondary" style="padding: 4px 8px; font-size: 0.8rem; color: #dc2626;">Definitief Verwijderen</button>
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
        <h2>Recente Definitieve Verwijderingen (Purge Audit Log)</h2>
        @if($recentPurges->isEmpty())
            <p>Geen definitieve verwijderingen geregistreerd.</p>
        @else
            {{-- Tabellen mogen op een telefoon van 390 px de pagina niet zijwaarts laten schuiven. --}}
<div class="ops-table-scroll" style="overflow-x: auto; max-width: 100%;"><table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
                <thead>
                    <tr style="text-align: left; border-bottom: 2px solid #e5e7eb;">
                        <th style="padding: 0.75rem;">Datum</th>
                        <th style="padding: 0.75rem;">Aanwinstnr</th>
                        <th style="padding: 0.75rem;">Titel</th>
                        <th style="padding: 0.75rem;">Verwijderd door</th>
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
