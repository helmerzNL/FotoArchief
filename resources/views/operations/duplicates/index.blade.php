@extends('layouts.app')
@section('title', 'Duplicaten Beheer - Vistora Operaties')
@section('content')
    @include('operations._nav')
    <p class="eyebrow">{{ __('operations.generated.t_a47c09ae1345f612') }}</p>
    <h1>{{ __('operations.generated.t_a5a58283325270fa') }}</h1>
    <p class="intro">{{ __('operations.generated.t_85e1a5dbd593f2d1') }}</p>

    @if($duplicates->isEmpty())
        <div class="notice">
            <p>{{ __('operations.generated.t_190338cf5c3f9143') }}</p>
        </div>
    @else
        {{-- Tabellen mogen op een telefoon van 390 px de pagina niet zijwaarts laten schuiven. --}}
<div class="ops-table-scroll" style="overflow-x: auto; max-width: 100%;"><table style="width: 100%; border-collapse: collapse; margin-top: 1.5rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 2px solid #e5e7eb;">
                    <th style="padding: 0.75rem;">Bestandsnaam</th>
                    <th style="padding: 0.75rem;">{{ __('operations.generated.t_32f3a0aac6c41170') }}</th>
                    <th style="padding: 0.75rem;">{{ __('operations.generated.t_94561214693936c1') }}</th>
                    <th style="padding: 0.75rem;">SHA-256</th>
                    <th style="padding: 0.75rem;">Datum</th>
                    <th style="padding: 0.75rem;">Actie</th>
                </tr>
            </thead>
            <tbody>
                @foreach($duplicates as $duplicate)
                    <tr style="border-bottom: 1px solid #e5e7eb;">
                        <td style="padding: 0.75rem;"><strong>{{ $duplicate->original_filename }}</strong></td>
                        <td style="padding: 0.75rem;">{{ $duplicate->uploadedBy?->name ?? 'Onbekend' }}</td>
                        <td style="padding: 0.75rem;">
                            @if($duplicate->duplicateOfAsset)
                                <a href="{{ route('admin.assets.show', $duplicate->duplicateOfAsset) }}">{{ $duplicate->duplicateOfAsset->accession_number }} &ndash; {{ $duplicate->duplicateOfAsset->title }}</a>
                            @else
                                {{ __('operations.generated.t_61913f33a879c2d0') }}
                            @endif
                        </td>
                        <td style="padding: 0.75rem;"><code style="font-size: 0.75rem;">{{ substr($duplicate->detected_sha256 ?? '', 0, 16) }}...</code></td>
                        <td style="padding: 0.75rem;">{{ $duplicate->created_at?->format('d-m-Y H:i') }}</td>
                        <td style="padding: 0.75rem;">
                            <a class="button" href="{{ route('admin.operations.duplicates.show', $duplicate) }}">{{ __('operations.generated.t_2f4c7284933f668d') }}</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>

        <div style="margin-top: 1.5rem;">
            {{ $duplicates->links() }}
        </div>
    @endif
@endsection
