<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('shell.title.default'))</title>
    <link rel="stylesheet" href="/app.css">
</head>
<body>
    <header>
        <a href="/">{{ __('shell.brand.name') }}</a>
        <nav><a href="{{ route('public.discover') }}">{{ __('shell.nav.discover') }}</a><a href="{{ route('public.collections.index') }}">{{ __('shell.nav.collections') }}</a></nav>
        <span>{{ __('shell.brand.tagline') }}</span>
        @auth
            <a href="{{ route('admin.assets.index') }}">{{ __('shell.nav.photos') }}</a>
            <a href="{{ route('catalogue.index') }}">{{ __('shell.nav.catalogue') }}</a>
            <a href="{{ route('exchange.index') }}">{{ __('shell.nav.exchange') }}</a>
            <a href="{{ route('admin.publications.index') }}">{{ __('shell.nav.publication') }}</a>
            <a href="{{ route('admin.suggestions.index') }}">{{ __('shell.nav.suggestions') }}</a>
            <a href="{{ route('identity.security.show') }}">{{ __('shell.nav.security') }}</a>
            @can('users.manage')
                <a href="{{ route('identity.users.index') }}">{{ __('shell.nav.identity') }}</a>
            @endcan
            @canany(['users.manage', 'audit.view', 'catalogue.manage', 'assets.update', 'assets.view'])
                <a href="{{ route('admin.operations.index') }}">{{ __('shell.nav.operations') }}</a>
            @endcanany
            <form method="post" action="/logout">@csrf<button class="secondary">{{ __('shell.nav.logout') }}</button></form>
        @endauth
        @guest <a href="{{ route('login') }}">{{ __('shell.nav.login') }}</a> @endguest
    </header>
    <main id="main">
        @if(session('status'))
            <div class="notice" role="status">{{ session('status') }}</div>
        @endif
        @if(session('invitation_url'))
            <div class="notice" role="status">
                <strong>{{ __('shell.notices.invitation_link.title') }}</strong>
                <p>{{ __('shell.notices.invitation_link.body') }}</p>
                <p><code>{{ session('invitation_url') }}</code></p>
            </div>
        @endif
        @if(session('recovery_codes'))
            <div class="notice" role="status">
                <strong>{{ __('shell.notices.recovery_codes.title') }}</strong>
                <p>{{ __('shell.notices.recovery_codes.body') }}</p>
                <ul>
                    @foreach(session('recovery_codes') as $code)
                        <li><code>{{ $code }}</code></li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if($errors->any())
            <div class="errors" role="alert">
                <strong>{{ __('shell.errors.check_input') }}</strong>
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        @yield('content')
    </main>
    <footer>{!! __('shell.footer') !!}</footer>
</body>
</html>
