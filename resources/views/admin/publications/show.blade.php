@extends('layouts.app')
@section('title', 'Publicatie - FotoArchief')
@section('content')
    @php($publication = $asset->publication)
    <p class="eyebrow">Publicatie</p>
    <h1>{{ $asset->title ?? $asset->accession_number }}</h1>
    <p class="intro">Status: <strong>{{ $publication?->status ?? 'concept, nog niet aangevraagd' }}</strong>
        @if($publication?->needsReReview())<br><strong>{{ __('publication.generated.t_1218c562633b3a69') }}</strong>@endif
    </p>
    @if($publication?->permalink_slug)
        <p>Permalink: <code>/foto/{{ $publication->permalink_slug }}</code></p>
    @endif

    <p><a href="{{ route('admin.publications.preview', $asset) }}">{{ __('publishwork.preview') }}</a></p>
    @include('admin.publications.checklist')
    <section class="card">
        <h2>{{ __('publishwork.changes') }}</h2>
        @if($publication?->approval_snapshot)
            @foreach($current as $field => $value)
                @if($value !== ($publication->approval_snapshot[$field] ?? null))
                    <h3>{{ __('publishwork.sections')[$field] }}</h3>
                    <p>{{ __('publishwork.approved') }}</p><pre>{{ json_encode($publication->approval_snapshot[$field] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    <p>{{ __('daily.current') }}</p><pre>{{ json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                @endif
            @endforeach
            @if($current === $publication->approval_snapshot)<p>{{ __('publishwork.unchanged') }}</p>@endif
        @else
            <p>{{ __('publishwork.no_snapshot') }}</p>
        @endif
    </section>

    @if(!$publication || in_array($publication->status, ['draft', 'revoked'], true) || $publication->needsReReview())
        <section class="card">
            <h2>{{ __('publication.generated.t_a798dc1087062d58') }}</h2>
            <form method="post" action="{{ route('admin.publications.submit', $asset) }}">
                @csrf
                <label><input type="checkbox" name="privacy_cleared" value="1" required> {{ __('publication.generated.t_8ee0972f5537b520') }}</label>
                <label>Downloadbeleid
                    <select name="download_policy">
                        <option value="preview_only">{{ __('publication.generated.t_2e191d996aeb8876') }}</option>
                        <option value="none">{{ __('publication.generated.t_e1a4ac883863c604') }}</option>
                    </select>
                </label>
                <label>Bronvermelding <input type="text" name="credit_line" maxlength="500"></label>
                <label>{{ __('publication.generated.t_bec92f3cceb1d55c') }} <input type="date" name="embargo_until"></label>
                <button type="submit">Aanvragen</button>
            </form>
        </section>
    @endif

    @if($publication?->status === 'in_review' && auth()->user()->hasPermission('assets.publish'))
        <section class="card">
            <h2>Beoordelen</h2>
            <form method="post" action="{{ route('admin.publications.publish', $asset) }}">@csrf<button type="submit">Publiceren</button></form>
            <form method="post" action="{{ route('admin.publications.reject', $asset) }}">
                @csrf
                <label>Reden <textarea name="reject_reason" required maxlength="2000"></textarea></label>
                <button type="submit">Afwijzen</button>
            </form>
        </section>
    @endif

    @if($publication?->status === 'published' && auth()->user()->hasPermission('assets.publish'))
        <section class="card">
            <h2>Intrekken</h2>
            <form method="post" action="{{ route('admin.publications.revoke', $asset) }}">
                @csrf
                <label>Reden <textarea name="revoked_reason" required maxlength="2000"></textarea></label>
                <button type="submit">{{ __('publication.generated.t_100e8cf989107ba5') }}</button>
            </form>
        </section>
    @endif

    <h2>Geschiedenis</h2>
    <ul>
    @foreach($events as $event)
        <li>{{ $event->created_at }} &middot; {{ $event->event_type }}</li>
    @endforeach
    </ul>
@endsection
