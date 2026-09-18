@extends('layouts.app')
@section('title', 'Importvoorbeeld - Vistora')
@section('content')
@php($summary = $import->summary ?? [])
<p class="eyebrow">Uitwisseling</p><h1>Importvoorbeeld</h1>
<p class="intro">{{ $import->original_filename ?: 'CSV-import' }} · {{ $import->statusLabel() }}</p>
@if($import->status === 'failed' && $import->failure_reason)
    <div class="errors" role="alert"><strong>{{ __('exchange.generated.t_563bf12eef2c1bef') }}</strong><p>{{ $import->failure_reason }}</p></div>
@endif
@if($import->isBusy())
    <div class="notice" role="status">{{ __('exchange.generated.t_b27f723e40f2b07d') }}</div>
@endif
<section class="card">
    <h2>Samenvatting</h2>
    <ul class="asset-list">
        <li>{{ __('exchange.generated.t_b1a92b6ea8e5cc12') }} {{ $summary['total'] ?? $import->row_count }}</li>
        <li>{{ __('exchange.generated.t_6a961bb7f91f851a') }} {{ $summary['ready'] ?? 0 }}</li>
        <li>{{ __('exchange.generated.t_0356d61894b4cab7') }} {{ $summary['unchanged'] ?? 0 }}</li>
        <li>{{ __('exchange.generated.t_10cfe7b329609f67') }} {{ $summary['error'] ?? 0 }}</li>
        <li>Bijgewerkt: {{ $summary['applied'] ?? 0 }}</li>
        <li>{{ __('exchange.generated.t_cea73f072fa2c48f') }} {{ $summary['failed'] ?? 0 }}</li>
    </ul>
    <p>{{ __('exchange.generated.t_be6af58f7c3971a4') }} <span class="checksum">{{ $import->content_sha256 }}</span></p>
    <p>Schrijfstand: {{ $import->write_mode === 'fill_empty' ? 'Alleen lege velden aanvullen' : 'Ingevulde waarden overschrijven' }}</p>
</section>
<section class="card">
    <h2>Kolomherkenning</h2>
    <table>
        <caption>{{ __('exchange.generated.t_edb840a8ad805ca4') }}</caption>
        <thead><tr><th scope="col">{{ __('exchange.generated.t_ffbad0c544d7fd59') }}</th><th scope="col">Veld</th></tr></thead>
        <tbody>
        @forelse($import->column_mapping ?? [] as $column)
            <tr>
                <td>{{ $column['column'] }}</td>
                <td>@if($column['status'] === 'mapped'){{ $column['field'] }}@elseif($column['status'] === 'duplicate'){{ __('exchange.generated.t_bac3e19dc82cbf1c') }}@else Genegeerd @endif</td>
            </tr>
        @empty
            <tr><td colspan="2">{{ __('exchange.generated.t_7e8c2f8fe13675ab') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</section>
<section class="card">
    <h2>{{ __('exchange.generated.t_f15297dbb22a3f74') }} {{ config('exchange.preview_rows') }})</h2>
    <table>
        <caption>{{ __('exchange.generated.t_b6c3ed17ce856958') }}</caption>
        <thead><tr><th scope="col">Rij</th><th scope="col">Archiefnummer</th><th scope="col">Status</th><th scope="col">Wijzigingen</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr>
                <td>{{ $row->row_number }}</td>
                <td>{{ $row->accession_number }}</td>
                <td>{{ ['ready' => 'Klaar', 'unchanged' => 'Ongewijzigd', 'error' => 'Fout', 'applied' => 'Bijgewerkt', 'failed' => 'Mislukt', 'pending' => 'Wacht'][$row->status] ?? $row->status }}</td>
                <td>
                    @foreach($row->changes ?? [] as $field => $change)
                        <div class="revision">{{ $field }}: <em>{{ is_array($change['before']) ? implode(', ', array_map('strval', $change['before'])) : ($change['before'] ?? '(leeg)') }}</em> &rarr; <strong>{{ is_array($change['after']) ? implode(', ', array_map('strval', $change['after'])) : ($change['after'] ?? '(leeg)') }}</strong></div>
                    @endforeach
                    @foreach($row->messages ?? [] as $message)
                        <div>{{ $message }}</div>
                    @endforeach
                </td>
            </tr>
        @empty
            <tr><td colspan="4">{{ __('exchange.generated.t_6747293b5b700fe4') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</section>
@can('assets.update')
@if(! $import->isBusy() && $import->status !== 'completed')
<section class="card">
    <h2>{{ __('exchange.generated.t_9509d232a016857f') }}</h2>
    <form method="post" action="{{ route('exchange.imports.analyse', $import) }}">
        @csrf
        <fieldset>
            <legend>Schrijfstand</legend>
            <label class="check"><input type="radio" name="write_mode" value="fill_empty" @checked($import->write_mode === 'fill_empty')> {{ __('exchange.generated.t_d9ffb26e8740581f') }}</label>
            <label class="check"><input type="radio" name="write_mode" value="overwrite" @checked($import->write_mode === 'overwrite')> {{ __('exchange.generated.t_114cd624209d7ee7') }}</label>
        </fieldset>
        <button class="secondary" type="submit">{{ __('exchange.generated.t_710c1c6a1e797413') }}</button>
    </form>
</section>
@endif
@if(in_array($import->status, ['analysed', 'failed'], true) && ($summary['ready'] ?? 0) > 0)
<section class="card">
    <h2>{{ __('exchange.generated.t_742818a029e9d54f') }}</h2>
    <p>{{ __('exchange.generated.t_8ed8e3cc5724ed72') }} {{ $summary['ready'] }} {{ __('exchange.generated.t_eb7fdcfae04f64f6') }}</p>
    <form method="post" action="{{ route('exchange.imports.confirm', $import) }}">
        @csrf
        <input type="hidden" name="checksum" value="{{ $import->content_sha256 }}">
        <button type="submit">{{ __('exchange.generated.t_12c90c01b10d364f') }} {{ $summary['ready'] }} {{ __('exchange.generated.t_6c2bf3a7a90036a5') }}</button>
    </form>
</section>
@endif
@endcan
<p><a href="{{ route('exchange.index') }}">{{ __('exchange.generated.t_a37b7b9e1dab31cf') }}</a></p>
@endsection