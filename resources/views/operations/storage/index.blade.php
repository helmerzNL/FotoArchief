@extends('layouts.app')
@section('title', 'Opslagmigratie - FotoArchief Operaties')
@section('content')
    <p class="eyebrow">Operaties &middot; Opslagbeheer</p>
    <h1>Veilige Opslagmigratie</h1>
    <p class="intro">Verhuis archiefbestanden tussen lokale opslag en S3-buckets met 100% SHA-256 integriteitsverificatie vóór omschakeling (cutover).</p>

    {{-- Huidige opslagschijven --}}
    <div class="grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
        @foreach($disks as $name => $disk)
            <div class="card">
                <h3>Schijf: {{ $name }}</h3>
                <p><strong>Driver:</strong> {{ $disk['driver'] }}</p>
                <p><strong>Archiefbestanden:</strong> {{ $disk['file_count'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- Migratie starten formulier --}}
    <section class="card" style="margin-bottom: 2rem;">
        <h2>Nieuwe Opslagmigratie Starten</h2>
        <p>Kopieert alle bestanden naar de doelschijf en verifieert checksums. Bronbestanden worden <strong>nooit</strong> vroegtijdig verwijderd.</p>

        <form method="post" action="{{ route('admin.operations.storage.start') }}" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 1rem; align-items: end; margin-top: 1rem;">
            @csrf
            <div>
                <label for="source_disk"><strong>Bronschijf:</strong></label>
                <select id="source_disk" name="source_disk" required style="width: 100%;">
                    @foreach($disks as $name => $disk)
                        <option value="{{ $name }}">{{ $name }} ({{ $disk['file_count'] }} bestanden)</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="target_disk"><strong>Doelschijf:</strong></label>
                <select id="target_disk" name="target_disk" required style="width: 100%;">
                    @foreach($disks as $name => $disk)
                        <option value="{{ $name }}">{{ $name }} ({{ $disk['driver'] }})</option>
                    @endforeach
                </select>
            </div>

            <div>
                <button type="submit" class="button">Start Migratie &amp; Verificatie</button>
            </div>
        </form>
    </section>

    {{-- Migratiehistorie en Cutover --}}
    <section class="card">
        <h2>Migratie-overzicht &amp; Cutover Status</h2>
        @if($migrations->isEmpty())
            <p>Nog geen opslagmigraties uitgevoerd.</p>
        @else
            <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
                <thead>
                    <tr style="text-align: left; border-bottom: 2px solid #e5e7eb;">
                        <th style="padding: 0.75rem;">Migratie ID</th>
                        <th style="padding: 0.75rem;">Van &rarr; Naar</th>
                        <th style="padding: 0.75rem;">Voortgang / Verificatie</th>
                        <th style="padding: 0.75rem;">Status</th>
                        <th style="padding: 0.75rem;">Datum</th>
                        <th style="padding: 0.75rem;">Actie</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($migrations as $m)
                        <tr style="border-bottom: 1px solid #e5e7eb;">
                            <td style="padding: 0.75rem;"><code>{{ substr($m->id, 0, 12) }}...</code></td>
                            <td style="padding: 0.75rem;"><strong>{{ $m->source_disk }}</strong> &rarr; <strong>{{ $m->target_disk }}</strong></td>
                            <td style="padding: 0.75rem;">
                                {{ $m->verified_files }} / {{ $m->total_files }} geverifieerd
                                @if($m->failed_files > 0)
                                    <span style="color: #dc2626;">({{ $m->failed_files }} mislukt)</span>
                                @endif
                            </td>
                            <td style="padding: 0.75rem;">
                                @if($m->status === 'verified')
                                    <span style="color: #059669; font-weight: bold;">Geverifieerd (Gereed voor Cutover)</span>
                                @elseif($m->status === 'cutover_completed')
                                    <span style="color: #2563eb; font-weight: bold;">Cutover Voltooid</span>
                                @elseif($m->status === 'completed')
                                    <span style="color: #4b5563;">Volledig Afgerond (Bron opgeruimd)</span>
                                @elseif($m->status === 'failed_verification')
                                    <span style="color: #dc2626; font-weight: bold;">Verificatie Mislukt</span>
                                @else
                                    <span>{{ $m->status }}</span>
                                @endif
                            </td>
                            <td style="padding: 0.75rem; font-size: 0.875rem;">{{ $m->created_at?->format('d-m-Y H:i') }}</td>
                            <td style="padding: 0.75rem;">
                                <div style="display: flex; gap: 0.5rem;">
                                    @if($m->status === 'verified')
                                        <form method="post" action="{{ route('admin.operations.storage.cutover', $m) }}">
                                            @csrf
                                            <button type="submit" class="button" style="padding: 4px 8px; font-size: 0.8rem;">Voer Cutover Uit</button>
                                        </form>
                                    @elseif($m->status === 'cutover_completed')
                                        <form method="post" action="{{ route('admin.operations.storage.cleanup', $m) }}">
                                            @csrf
                                            <button type="submit" class="secondary" style="padding: 4px 8px; font-size: 0.8rem; color: #dc2626;" onclick="return confirm('Weet je zeker dat je de bronbestanden wilt opruimen?');">Ruim Bron Op</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
    @include('operations.runs._panel', ['runs' => $runs])
@endsection
