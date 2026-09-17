@extends('layouts.app')
@section('title', 'Taak Details - ' . $upload->original_filename)
@section('content')
    @include('operations._nav')
    <p class="eyebrow"><a href="{{ route('admin.operations.processing.index') }}">{{ __('operations.generated.t_e535b611891cf0cb') }}</a></p>
    <h1>{{ __('operations.generated.t_5a0ad2d4121bf2cc') }}</h1>
    <p class="intro">{{ __('operations.generated.t_4d22d7739a161732') }} <code>{{ $upload->id }}</code>.</p>

    <div class="grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
        <section class="card">
            <h2>{{ __('operations.generated.t_4e6c76c61b5baefa') }}</h2>
            <p><strong>{{ __('operations.generated.t_33689977a016d0da') }}</strong> {{ $upload->original_filename }}</p>
            <p><strong>Grootte:</strong> {{ number_format($upload->byte_size / 1024, 1) }} {{ __('operations.generated.t_30b6c7d752ca6cc3') }}{{ number_format($upload->byte_size) }} bytes)</p>
            <p><strong>Status:</strong> <strong>{{ $upload->status }}</strong></p>
            <p><strong>{{ __('operations.generated.t_638c014433eb9f8d') }}</strong> {{ $upload->attempts }}</p>
            <p><strong>{{ __('operations.generated.t_f802d6c011246bbf') }}</strong> <code>{{ $upload->storage_disk }}:{{ $upload->storage_key }}</code></p>
            <p><strong>{{ __('operations.generated.t_d4cdd72bedd54eee') }}</strong> {{ $upload->uploadedBy?->name ?? 'Onbekend' }}</p>
            <p><strong>Aangemaakt:</strong> {{ $upload->created_at?->format('d-m-Y H:i:s') }}</p>
            <p><strong>{{ __('operations.generated.t_2fd4ac91bc13347f') }}</strong> {{ $upload->started_at ? $upload->started_at->format('d-m-Y H:i:s') : '-' }}</p>
        </section>

        <section class="card">
            <h2>{{ __('operations.generated.t_85cb3101caa26f65') }}</h2>
            @if($upload->failure_reason)
                <div style="background: #fef2f2; border-left: 4px solid #ef4444; padding: 1rem; margin-bottom: 1.5rem;">
                    <strong>Foutmelding:</strong>
                    <p style="color: #991b1b; margin-top: 0.25rem;">{{ $upload->failure_reason }}</p>
                </div>
            @else
                <p style="color: #059669;">{{ __('operations.generated.t_734c062961b1d1f0') }}</p>
            @endif

            <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                @if(in_array($upload->status, ['failed', 'rejected']) || ($upload->status === 'running' && $upload->started_at?->lt(now()->subMinutes(4))))
                    <form method="post" action="{{ route('admin.operations.processing.retry', $upload) }}">
                        @csrf
                        <button type="submit" class="button">{{ __('operations.generated.t_c868cd37a569f1ef') }}</button>
                    </form>
                @endif
                @if($upload->status === 'running')
                    <form method="post" action="{{ route('admin.operations.processing.cancel', $upload) }}">
                        @csrf
                        <button type="submit" class="secondary" style="color: #dc2626;">{{ __('operations.generated.t_2c7c7652db3a0744') }}</button>
                    </form>
                @endif
            </div>
        </section>
    </div>
@endsection
