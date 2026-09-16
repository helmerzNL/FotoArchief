<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'FotoArchief')</title>
    <link rel="stylesheet" href="/app.css">
</head>
<body>
    <header><a href="/">FotoArchief</a><span>Jouw geschiedenis, zorgvuldig bewaard</span>@auth<a href="{{ route('admin.assets.index') }}">Foto’s</a><a href="{{ route('catalogue.index') }}">Catalogus</a><a href="{{ route('exchange.index') }}">Uitwisseling</a><a href="{{ route('identity.security.show') }}">Beveiliging</a>@can('users.manage')<a href="{{ route('identity.users.index') }}">Identiteit</a>@endcan<form method="post" action="/logout">@csrf<button class="secondary">Uitloggen</button></form>@endauth</header>
    <main id="main">
        @if(session('status'))
            <div class="notice" role="status">{{ session('status') }}</div>
        @endif
        @if(session('invitation_url'))
            <div class="notice" role="status">
                <strong>Eenmalige uitnodigingslink</strong>
                <p>Kopieer deze link nu en deel hem via een kanaal dat je vertrouwt. FotoArchief toont hem niet opnieuw.</p>
                <p><code>{{ session('invitation_url') }}</code></p>
            </div>
        @endif
        @if(session('recovery_codes'))
            <div class="notice" role="status">
                <strong>Eenmalige herstelcodes</strong>
                <p>Bewaar deze codes offline. Elke code werkt één keer en wordt hierna niet meer getoond.</p>
                <ul>
                    @foreach(session('recovery_codes') as $code)
                        <li><code>{{ $code }}</code></li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if($errors->any())
            <div class="errors" role="alert">
                <strong>Controleer je invoer</strong>
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        @yield('content')
    </main>
    <footer>FotoArchief &middot; Ontwikkelversie &middot; Bewaar altijd een onafhankelijke backup</footer>
</body>
</html>
