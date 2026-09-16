@extends('layouts.app')
@section('title', __('onboarding.dashboard.title'))
@section('content')
    <p class="eyebrow">{{ __('onboarding.dashboard.eyebrow') }}</p>
    <h1>{{ __('onboarding.dashboard.heading', ['name' => auth()->user()->name]) }}</h1>
    <p class="intro">{{ __('onboarding.dashboard.intro') }}</p>
    <div class="grid">
        <section class="card"><h2>{{ __('onboarding.dashboard.database') }}</h2><p>{{ __('onboarding.dashboard.database_summary', ['database' => config('database.connections.pgsql.database')]) }}</p></section>
        <section class="card"><h2>{{ __('onboarding.dashboard.storage') }}</h2><p>{{ config('filesystems.default') === 'local' ? __('onboarding.dashboard.storage_local') : __('onboarding.dashboard.storage_s3') }}</p></section>
        <section class="card"><h2>{{ __('onboarding.dashboard.installation') }}</h2><p>{{ __('onboarding.dashboard.installation_locked') }}</p></section>
        <section class="card"><h2>{{ __('onboarding.dashboard.first_photos') }}</h2><p>{{ __('onboarding.dashboard.first_photos_help') }}</p><a class="button" href="{{ route('admin.assets.index') }}">{{ __('onboarding.dashboard.first_photos_link') }}</a></section>
    </div>
    <form method="post" action="/logout">@csrf<button class="secondary" type="submit">{{ __('shell.nav.logout') }}</button></form>
@endsection
