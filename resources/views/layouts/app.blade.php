<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
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
@php
    // The workspace (staff) gets a sidebar on wide screens and a bottom bar on phones;
    // public pages, login and setup keep a simple top bar.
    $workspace = auth()->check() && ! request()->routeIs('public.*') && ! request()->is('login', 'setup', 'setup/*');
    $activeGroup = collect($staffGroups ?? [])->firstWhere('active', true);
@endphp
<body class="{{ $workspace ? 'workspace-shell' : 'public-shell' }}">
    <a class="skip-link" href="#main">{{ __('shell.nav.skip') }}</a>
    @include('layouts.partials.icons')
    @if($workspace)
        <div class="app-shell">
            <aside id="sidebar" class="sidebar glass">
                <div class="sidebar-head">
                    <a class="brand" href="{{ route('admin.assets.index') }}" aria-label="{{ __('shell.brand.name') }}"><svg class="brand-mark" viewBox="0 0 64 64" aria-hidden="true"><path fill="currentColor" d="M14 14H42V22H22V42H14ZM50 50H26V42H42V26H50Z"/></svg><span class="brand-name">{{ __('shell.brand.name') }}</span></a>
                    @if($archiveProfile)<p class="sidebar-society">{{ $archiveProfile->name }}</p>@endif
                </div>
                <nav class="staff-nav" aria-label="{{ __('shell.nav.staff') }}">
                    <ul>
                        @foreach($staffGroups as $group)
                            <li><a class="nav-link" href="{{ $group['href'] }}" title="{{ $group['label'] }}"@if($group['active']) aria-current="page"@endif><svg class="icon" aria-hidden="true" focusable="false"><use href="#i-{{ $group['icon'] }}"/></svg><span class="nav-label">{{ $group['label'] }}</span></a></li>
                        @endforeach
                    </ul>
                </nav>
                <div class="sidebar-foot">
                    <button id="sidebar-toggle" type="button" class="quiet" hidden aria-expanded="true" aria-controls="sidebar" data-label-collapse="{{ __('shell.sidebar.collapse') }}" data-label-expand="{{ __('shell.sidebar.expand') }}"><svg class="icon icon-collapse" aria-hidden="true" focusable="false"><use href="#i-chevrons-left"/></svg><svg class="icon icon-expand" aria-hidden="true" focusable="false"><use href="#i-chevrons-right"/></svg><span class="nav-label" data-toggle-label>{{ __('shell.sidebar.collapse') }}</span></button>
                </div>
            </aside>
            <div class="app-main">
                <header class="topbar glass">
                    <a class="brand brand-compact" href="{{ route('admin.assets.index') }}" aria-label="{{ __('shell.brand.name') }}"><svg class="brand-mark" viewBox="0 0 64 64" aria-hidden="true"><path fill="currentColor" d="M14 14H42V22H22V42H14ZM50 50H26V42H42V26H50Z"/></svg></a>
                    @if($archiveProfile)<p class="topbar-society">{{ $archiveProfile->name }}</p>@endif
                    <div class="header-tools">
                        <a class="button quiet" href="{{ route('public.home') }}"><svg class="icon" aria-hidden="true" focusable="false"><use href="#i-external"/></svg><span>{{ __('shell.nav.view_site') }}</span></a>
                        <details class="menu" id="appearance-menu">
                            <summary class="menu-button" aria-label="{{ __('shell.nav.account') }}"><span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) auth()->user()->name, 0, 1)) }}</span></summary>
                            <div class="menu-panel glass">
                                <p class="menu-title">{{ auth()->user()->name }}</p>
                                @include('layouts.partials.appearance')
                                <ul class="menu-links">
                                    <li><a href="{{ route('identity.security.show') }}"><svg class="icon" aria-hidden="true" focusable="false"><use href="#i-shield"/></svg>{{ __('shell.nav.security') }}</a></li>
                                    <li><form method="post" action="/logout">@csrf<button class="quiet" type="submit"><svg class="icon" aria-hidden="true" focusable="false"><use href="#i-logout"/></svg>{{ __('shell.nav.logout') }}</button></form></li>
                                </ul>
                            </div>
                        </details>
                    </div>
                </header>
                @if($activeGroup && count($activeGroup['items']) > 1)
                    <nav class="subnav" aria-label="{{ __('shell.nav.section', ['group' => $activeGroup['label']]) }}">
                        <ul>
                            @foreach($activeGroup['items'] as $item)
                                <li><a href="{{ $item['href'] }}"@if($item['active']) aria-current="page"@endif>{{ $item['label'] }}</a></li>
                            @endforeach
                        </ul>
                    </nav>
                @endif
    @else
        <header class="site-header glass">
            <a class="brand" href="/" aria-label="{{ __('shell.brand.name') }}"><svg class="brand-mark" viewBox="0 0 64 64" aria-hidden="true"><path fill="currentColor" d="M14 14H42V22H22V42H14ZM50 50H26V42H42V26H50Z"/></svg><span>{{ __('shell.brand.name') }}</span></a>
            @if($archiveProfile)<p class="archive-sender">{{ $archiveProfile->name }}</p>@endif
            <nav class="public-nav" aria-label="{{ __('shell.nav.public') }}">
                <a href="{{ route('public.discover') }}" aria-current="{{ request()->routeIs('public.discover', 'public.home') ? 'page' : 'false' }}">{{ __('shell.nav.discover') }}</a>
                <a href="{{ route('public.collections.index') }}" aria-current="{{ request()->routeIs('public.collections.*') ? 'page' : 'false' }}">{{ __('shell.nav.collections') }}</a>
            </nav>
            <div class="header-tools">
                <details class="menu" id="appearance-menu">
                    <summary class="menu-button menu-button-labelled"><svg class="icon" aria-hidden="true" focusable="false"><use href="#i-contrast"/></svg><span class="menu-button-label">{{ __('shell.accent.toggle') }}</span></summary>
                    <div class="menu-panel glass">@include('layouts.partials.appearance')</div>
                </details>
                @auth
                    <a class="button secondary login-link" href="{{ route('admin.assets.index') }}">{{ __('shell.nav.to_workspace') }}</a>
                @endauth
                @guest <a class="button secondary login-link" href="{{ route('login') }}">{{ __('shell.nav.login') }}</a> @endguest
            </div>
        </header>
    @endif
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
    <footer class="site-footer"><strong>{{ __('shell.brand.name') }}</strong><span>{{ __('shell.brand.tagline') }}</span>@if($archiveProfile)<span>{{ __('shell.archive.footer', ['name' => $archiveProfile->name]) }}</span>@endif<small>{!! __('shell.footer') !!}</small></footer>
    @if($workspace)
            </div>
            <nav class="bottom-nav glass" aria-label="{{ __('shell.nav.staff') }}">
                <ul>
                    @foreach($staffGroups as $group)
                        <li><a class="nav-link" href="{{ $group['href'] }}"@if($group['active']) aria-current="page"@endif><svg class="icon" aria-hidden="true" focusable="false"><use href="#i-{{ $group['icon'] }}"/></svg><span class="nav-label">{{ $group['label'] }}</span></a></li>
                    @endforeach
                </ul>
            </nav>
        </div>
    @endif
</body>
</html>
