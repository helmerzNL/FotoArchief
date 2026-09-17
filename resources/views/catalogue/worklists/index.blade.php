@extends('layouts.app')
@section('title', 'Werklijsten & Curatie - FotoArchief')
@section('content')
<p class="eyebrow"><a href="{{ route('catalogue.index') }}">{{ __('catalogue.generated.t_c09130ba550190b2') }}</a></p>
<h1>{{ __('catalogue.generated.t_91012d8c6a8d01a6') }}</h1>

@if(session('status'))
    <div class="card" style="border-color: #16a34a; background-color: #f0fdf4;">
        <p>{{ session('status') }}</p>
    </div>
@endif

<section class="card">
    <h2>{{ __('catalogue.generated.t_0341b28628a73af8') }}</h2>
    <p>{{ __('catalogue.generated.t_4e282598945e5d31') }}</p>
    <div class="grid">
        <div class="card" style="border: 1px solid #e5e7eb;">
            <h3>{{ __('catalogue.generated.t_486e698d1540bafc') }}</h3>
            <p><strong>{{ $stats['missing_dates'] }}</strong> {{ __('catalogue.generated.t_baa09face9e5a506') }}</p>
            <a href="{{ route('catalogue.worklists.create', ['type' => 'missing_date']) }}" class="button secondary">{{ __('catalogue.generated.t_901561ca3190e58f') }}</a>
        </div>
        <div class="card" style="border: 1px solid #e5e7eb;">
            <h3>{{ __('catalogue.generated.t_0bd7881e66d6d77d') }}</h3>
            <p><strong>{{ $stats['missing_rights'] }}</strong> {{ __('catalogue.generated.t_d93085f20725a163') }}</p>
            <a href="{{ route('catalogue.worklists.create', ['type' => 'missing_rights']) }}" class="button secondary">{{ __('catalogue.generated.t_12a76df6e43918f7') }}</a>
        </div>
        <div class="card" style="border: 1px solid #e5e7eb;">
            <h3>{{ __('catalogue.generated.t_f7a3ccd61ab4f183') }}</h3>
            <p><strong>{{ $stats['missing_identification'] }}</strong> {{ __('catalogue.generated.t_832423faab3d68eb') }}</p>
            <a href="{{ route('catalogue.worklists.create', ['type' => 'missing_identification']) }}" class="button secondary">{{ __('catalogue.generated.t_55aed409b9150f81') }}</a>
        </div>
        <div class="card" style="border: 1px solid #e5e7eb;">
            <h3>{{ __('catalogue.generated.t_c24fc63a164980ac') }}</h3>
            <p><strong>{{ $stats['missing_provenance'] }}</strong> {{ __('catalogue.generated.t_705e6bbd4be4a230') }}</p>
            <a href="{{ route('catalogue.worklists.create', ['type' => 'missing_provenance']) }}" class="button secondary">{{ __('catalogue.generated.t_ea854bc71d66f096') }}</a>
        </div>
    </div>
</section>

<section class="card">
    <div class="actions" style="margin-bottom: 1rem;">
        <a href="{{ route('catalogue.worklists.create') }}" class="button">{{ __('catalogue.generated.t_a4a2eed97210fa82') }}</a>
    </div>

    <h2>{{ __('catalogue.generated.t_28f8d047a0e08480') }}</h2>
    <ul class="asset-list">
        @forelse($worklists as $wl)
            <li>
                <a href="{{ route('catalogue.worklists.show', $wl) }}"><strong>{{ $wl->title }}</strong></a>
                <small>
                    {{ __('catalogue.fragments.type') }}: {{ $wl->worklist_type }} {{ __('catalogue.generated.t_34ba21340c9968af') }} {{ $wl->status }} {{ __('catalogue.generated.t_8712973af30ed56b') }} {{ $wl->progressPercentage() }}% ({{ $wl->completed_items_count }}/{{ $wl->items_count }})
                    @if($wl->assignedTo) {{ __('catalogue.generated.t_b59665ee5dd7ac0b') }} {{ $wl->assignedTo->name }} @endif
                    {{ __('catalogue.generated.t_abd6bb3b2e8de62c') }} {{ $wl->createdBy->name }}
                </small>
            </li>
        @empty
            <li>{{ __('catalogue.generated.t_ca1628e5dd6757f6') }}</li>
        @endforelse
    </ul>
</section>
@endsection
