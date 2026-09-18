<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('shell.title.default'))</title>
    <meta name="theme-color" content="#f7f3ec">
    <meta name="application-name" content="{{ __('shell.brand.name') }}">
    <meta property="og:site_name" content="{{ __('shell.brand.name') }}">
    <meta property="og:title" content="{{ __('shell.brand.tagline') }}">
    <meta property="og:image" content="{{ url('/brand/social-preview.png') }}">
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
    <link rel="apple-touch-icon" href="/brand/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="stylesheet" href="/brand/tokens.css?v={{ hash_file('sha256', public_path('brand/tokens.css')) }}">
    <script src="/theme.js?v={{ hash_file('sha256', public_path('theme.js')) }}"></script>
    <link rel="stylesheet" href="/app.css?v={{ hash_file('sha256', public_path('app.css')) }}">
</head>
<body class="{{ request()->routeIs('public.*') ? 'public-shell' : 'workspace-shell' }}">
    <a class="skip-link" href="#main">{{ __('shell.nav.skip') }}</a>
    <header class="site-header">
        <a class="brand" href="/" aria-label="{{ __('shell.brand.name') }}"><svg class="brand-mark" viewBox="0 0 64 64" aria-hidden="true"><path fill="currentColor" d="M14 14H42V22H22V42H14ZM50 50H26V42H42V26H50Z"/></svg><span>{{ __('shell.brand.name') }}</span></a>
        <nav class="public-nav" aria-label="{{ __('shell.nav.public') }}">
            <a href="{{ route('public.discover') }}" aria-current="{{ request()->routeIs('public.discover', 'public.home') ? 'page' : 'false' }}">{{ __('shell.nav.discover') }}</a>
            <a href="{{ route('public.collections.index') }}" aria-current="{{ request()->routeIs('public.collections.*') ? 'page' : 'false' }}">{{ __('shell.nav.collections') }}</a>
        </nav>
        <div class="header-tools">
            <div id="theme-control" class="theme-control" hidden>
                <label for="theme-preference">{{ __('shell.theme.label') }}</label>
                <select id="theme-preference" aria-describedby="theme-status">
                    <option value="system">{{ __('shell.theme.system') }}</option>
                    <option value="light">{{ __('shell.theme.light') }}</option>
                    <option value="dark">{{ __('shell.theme.dark') }}</option>
                </select>
                <p id="theme-status" role="status" data-storage-error="{{ __('shell.theme.storage_error') }}"></p>
            </div>
            @auth <form method="post" action="/logout">@csrf<button class="secondary">{{ __('shell.nav.logout') }}</button></form> @endauth
            @guest <a class="button secondary login-link" href="{{ route('login') }}">{{ __('shell.nav.login') }}</a> @endguest
        </div>
    </header>
    @auth
        <nav class="staff-nav" aria-label="{{ __('shell.nav.staff') }}">
            <a href="{{ route('admin.assets.index') }}">{{ __('shell.nav.photos') }}</a>
            <a href="{{ route('catalogue.index') }}">{{ __('shell.nav.catalogue') }}</a>
            <a href="{{ route('exchange.index') }}">{{ __('shell.nav.exchange') }}</a>
            <a href="{{ route('admin.publications.index') }}">{{ __('shell.nav.publication') }}</a>
            <a href="{{ route('admin.suggestions.index') }}">{{ __('shell.nav.suggestions') }}</a>
            <a href="{{ route('identity.security.show') }}">{{ __('shell.nav.security') }}</a>
            @can('assets.view')<a href="{{ route('admin.operations.notifications') }}">{{ __('evidence.notifications') }}</a>@endcan
            @can('users.manage')
                <a href="{{ route('identity.users.index') }}">{{ __('shell.nav.identity') }}</a>
            @endcan
            @canany(['users.manage', 'audit.view', 'catalogue.manage', 'assets.update', 'assets.view'])
                <a href="{{ route('admin.operations.index') }}">{{ __('shell.nav.operations') }}</a>
            @endcanany
        </nav>
    @endauth
    <main id="main" tabindex="-1">
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
    <footer class="site-footer"><strong>{{ __('shell.brand.name') }}</strong><span>{{ __('shell.brand.tagline') }}</span><small>{!! __('shell.footer') !!}</small></footer>
</body>
</html>
