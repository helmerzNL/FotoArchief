@extends('layouts.app')
@section('title', 'Opslagmigratie - Vistora Operaties')
@section('content')
    @include('operations._nav')
    <p class="eyebrow">{{ __('operations.generated.t_a88b6d66079e295c') }}</p>
    <h1>{{ __('operations.generated.t_c6c4a71618c38dc9') }}</h1>
    <p class="intro">{{ __('operations.generated.t_0a972a485a5a786d') }}</p>

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
        <h2>{{ __('operations.generated.t_317f8d46d1774963') }}</h2>
        <p>{{ __('operations.generated.t_65761a2f9bf90fa8') }} <strong>nooit</strong> {{ __('operations.generated.t_c946349e09a2527a') }}</p>

        <form method="post" action="{{ route('admin.operations.storage.start') }}" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 1rem; align-items: end; margin-top: 1rem;">
            @csrf
            <div>
                <label for="source_disk"><strong>Bronschijf:</strong></label>
                <select id="source_disk" name="source_disk" required style="width: 100%;">
                    @foreach($disks as $name => $disk)
                        <option value="{{ $name }}">{{ $name }} ({{ $disk['file_count'] }} {{ __('operations.fragments.files') }})</option>
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
                <button type="submit" class="button">{{ __('operations.generated.t_75c99008d444cb03') }}</button>
            </div>
        </form>
    </section>

    {{-- Migratiehistorie en Cutover --}}
    <section class="card">
        <h2>{{ __('operations.generated.t_88bc9ea6d3b96705') }}</h2>
        @if($migrations->isEmpty())
            <p>{{ __('operations.generated.t_3d77d2e9e7169603') }}</p>
        @else
            {{-- Tabellen mogen op een telefoon van 390 px de pagina niet zijwaarts laten schuiven. --}}
<div class="ops-table-scroll" style="overflow-x: auto; max-width: 100%;"><table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
                <thead>
                    <tr style="text-align: left; border-bottom: 2px solid #e5e7eb;">
                        <th style="padding: 0.75rem;">{{ __('operations.generated.t_6343f12e763b501e') }}</th>
                        <th style="padding: 0.75rem;">{{ __('operations.generated.t_a430a7ff97aebf03') }}</th>
                        <th style="padding: 0.75rem;">{{ __('operations.generated.t_cdc3a95485b8f9a2') }}</th>
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
                                {{ $m->verified_files }} / {{ $m->total_files }} {{ __('operations.fragments.verified') }}
                                @if($m->failed_files > 0)
                                    <span style="color: #dc2626;">({{ $m->failed_files }} {{ __('operations.fragments.failed') }})</span>
                                @endif
                            </td>
                            <td style="padding: 0.75rem;">
                                @if($m->status === 'verified')
                                    <span style="color: #059669; font-weight: bold;">{{ __('operations.generated.t_ed7fe4588ffd5122') }}</span>
                                @elseif($m->status === 'cutover_completed')
                                    <span style="color: #2563eb; font-weight: bold;">{{ __('operations.generated.t_87405a9fd26db1db') }}</span>
                                @elseif($m->status === 'completed')
                                    <span style="color: #4b5563;">{{ __('operations.generated.t_212c569285f362fb') }}</span>
                                @elseif($m->status === 'failed_verification')
                                    <span style="color: #dc2626; font-weight: bold;">{{ __('operations.generated.t_bc348a017bf516aa') }}</span>
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
                                            <button type="submit" class="button" style="padding: 4px 8px; font-size: 0.8rem;">{{ __('operations.generated.t_db1c7298f6d885fe') }}</button>
                                        </form>
                                    @elseif($m->status === 'cutover_completed')
                                        <form method="post" action="{{ route('admin.operations.storage.cleanup', $m) }}">
                                            @csrf
                                            <button type="submit" class="secondary" style="padding: 4px 8px; font-size: 0.8rem; color: #dc2626;" onclick="return confirm('Weet je zeker dat je de bronbestanden wilt opruimen?');">{{ __('operations.generated.t_bd266536e87d4c65') }}</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </section>
    @include('operations.runs._panel', ['runs' => $runs])
@endsection
