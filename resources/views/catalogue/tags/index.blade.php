@extends('layouts.app')
@section('title', 'Tags & Trefwoorden - Vistora')
@section('content')
<p class="eyebrow"><a href="{{ route('catalogue.index') }}">{{ __('catalogue.generated.t_c09130ba550190b2') }}</a></p>
<h1>{{ __('catalogue.generated.t_edf0cac4b05289f5') }}</h1>

@if(session('status'))
    <div class="card" style="border-color: #16a34a; background-color: #f0fdf4;">
        <p>{{ session('status') }}</p>
    </div>
@endif

<section class="card">
    <div class="actions" style="margin-bottom: 1rem;">
        <a href="{{ route('catalogue.tags.create') }}" class="button">{{ __('catalogue.generated.t_8bc07be057f85631') }}</a>
    </div>

    <form method="get" action="{{ route('catalogue.tags.index') }}" style="margin-bottom: 1.5rem;">
        <div class="grid">
            <div>
                <label for="q">{{ __('catalogue.generated.t_523a38a5952e78f9') }}</label>
                <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="{{ __('catalogue.generated.t_0397b79e3329cb7b') }}">
            </div>
        </div>
        <div class="actions">
            <button type="submit">Zoeken</button>
            @if(request('q'))
                <a href="{{ route('catalogue.tags.index') }}" class="button secondary">Wissen</a>
            @endif
        </div>
    </form>

    <ul class="asset-list">
        @forelse($tags as $tag)
            <li>
                <a href="{{ route('catalogue.tags.show', $tag) }}"><strong>{{ $tag->name }}</strong></a>
                <small>
                    {{ $tag->assets_count }} {{ $tag->assets_count === 1 ? 'foto' : 'foto’s' }}
                    @if($tag->synonyms->isNotEmpty())
                        {{ __('catalogue.generated.t_80e884dede0c4eb4') }} {{ $tag->synonyms->pluck('name')->join(', ') }}
                    @endif
                    @if($tag->description)
                        · {{ Str::limit($tag->description, 60) }}
                    @endif
                </small>
            </li>
        @empty
            <li>{{ __('catalogue.generated.t_869ca148f8c01836') }}</li>
        @endforelse
    </ul>
</section>
@endsection
