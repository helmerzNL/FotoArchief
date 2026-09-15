<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'FotoArchief')</title>
    <link rel="stylesheet" href="/app.css">
</head>
<body>
    <header><a href="/">FotoArchief</a><span>Jouw geschiedenis, zorgvuldig bewaard</span>@auth<a href="{{ route('admin.assets.index') }}">Foto’s</a><form method="post" action="/logout">@csrf<button class="secondary">Uitloggen</button></form>@endauth</header>
    <main id="main">
        @if(session('status'))
            <div class="notice" role="status">{{ session('status') }}</div>
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
