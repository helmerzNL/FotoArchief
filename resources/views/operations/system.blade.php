@extends('layouts.app')
@section('title', __('operations.update.title'))
@section('content')
    @php
        $states = __('operations.update.state');
        $channels = __('operations.update.channels');
        $methods = __('operations.update.methods');
        $steps = __('operations.update.steps');
        $exampleVersion = $update['latest'] ?? 'X.Y.Z';
        $checkedAt = $update['checkedAt'] !== null
            ? \Illuminate\Support\Carbon::createFromTimestamp($update['checkedAt'])->timezone(config('app.timezone'))->format('d-m-Y H:i')
            : __('operations.update.never');
        $otherMethods = array_values(array_filter($deployments, fn (string $method): bool => $method !== $deployment));
    @endphp
    <h1>{{ __('operations.update.title') }}</h1>
    <p class="intro">{{ __('operations.update.intro') }}</p>

    <section class="card">
        <dl>
            <dt>{{ __('operations.update.installed') }}</dt>
            <dd>v{{ $update['current'] }}</dd>
            <dt>{{ __('operations.update.channel') }}</dt>
            <dd>{{ $channels[$channel] ?? $channel }}</dd>
            <dt>{{ __('operations.update.image_tag') }}</dt>
            <dd><code>{{ $releaseTag }}</code></dd>
            <dt>{{ __('operations.update.latest') }}</dt>
            <dd>{{ $update['latest'] !== null ? 'v'.$update['latest'] : __('operations.update.none') }}</dd>
            <dt>{{ __('operations.update.checked_at') }}</dt>
            <dd>{{ $checkedAt }}</dd>
            <dt>{{ __('operations.update.status') }}</dt>
            <dd data-update-state="{{ $update['state'] }}">{{ $states[$update['state']] ?? $update['state'] }}</dd>
        </dl>
        @if($update['error'] !== null)
            <p role="alert" title="{{ $update['errorDetail'] }}">{{ __('operations.update.error', ['reason' => $update['error']]) }}</p>
        @endif
        @if($update['url'] !== null)
            <p><a href="{{ $update['url'] }}" rel="noopener">{{ __('operations.update.release_notes', ['version' => $update['latest']]) }}</a></p>
        @endif
        @if($update['enabled'])
            <form method="POST" action="{{ route('admin.operations.system.check') }}">
                @csrf
                <button type="submit">{{ __('operations.update.check_now') }}</button>
            </form>
        @endif
        <p>{{ __('operations.update.privacy') }}</p>
        @if($channel === \App\Support\ReleaseChannel::BETA)
            <p>{{ __('operations.update.beta_note') }}</p>
        @endif
    </section>

    <section class="card">
        <h2>{{ __('operations.update.how_title') }}</h2>
        <p>{{ __('operations.update.how_intro') }}</p>
        <h3>{{ __('operations.update.detected', ['method' => $methods[$deployment]]) }}</h3>
        <ol data-update-steps="{{ $deployment }}">
            @foreach($steps[$deployment] as $step)
                <li>{{ str_replace(':latest', $exampleVersion, $step) }}</li>
            @endforeach
        </ol>
        <details>
            <summary>{{ __('operations.update.other_methods') }}</summary>
            @foreach($otherMethods as $method)
                <h3>{{ $methods[$method] }}</h3>
                <ol data-update-steps="{{ $method }}">
                    @foreach($steps[$method] as $step)
                        <li>{{ str_replace(':latest', $exampleVersion, $step) }}</li>
                    @endforeach
                </ol>
            @endforeach
        </details>
        <p>{{ __('operations.update.deployment_hint') }}</p>
    </section>
@endsection
