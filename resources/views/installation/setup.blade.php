@extends('layouts.app')
@section('title', 'FotoArchief installeren')
@section('content')
    <p class="eyebrow">Eerste installatie</p>
    <h1>Een thuis voor je fotoarchief</h1>
    <p class="intro">Verbind een lege PostgreSQL-database, kies private opslag en maak je beheerdersaccount. De wizard wordt na installatie afgesloten.</p>
    <ol class="steps" aria-label="Installatiestappen">
        <li @if(!$authorized) aria-current="step" @endif>1. Toegang bevestigen</li>
        <li @if($authorized) aria-current="step" @endif>2. Verbinden en instellen</li>
        <li>3. Inloggen</li>
    </ol>
    @unless($authorized)
        <section class="card narrow">
            <h2>Alleen de eigenaar kan installeren</h2>
            <p>Haal je eenmalige code op met <code>php artisan installation:prepare</code>. In Docker: <code>docker compose exec --user www-data app php artisan installation:prepare</code>.</p>
            <p>Bij webhosting zonder terminal vind je de code via het private bestandsbeheer in <code>storage/app/installation/setup-code.txt</code>. Deel deze code niet en plaats nooit de volledige applicatie in de publieke webmap.</p>
            <form method="post" action="/setup/unlock">
                @csrf
                <label for="code">Installatiecode</label>
                <input id="code" name="code" type="password" autocomplete="off" required maxlength="100">
                <button type="submit">Installatie ontgrendelen</button>
            </form>
        </section>
    @else
        @if($resuming)
            <div class="notice">Een eerdere installatie is onderbroken. Gebruik exact dezelfde database, opslaggegevens en het oorspronkelijke e-mailadres om veilig te hervatten. Een bestaande beheerder wordt niet overschreven.</div>
        @endif
        <p>Je toegang is 20 minuten geldig. Gebruik HTTPS buiten een lokale testomgeving. Geheimen worden nooit opnieuw in het formulier getoond.</p>
        <form method="post" action="/setup/complete">
            @csrf
            <div class="grid">
                <fieldset class="card">
                    <legend>Database</legend>
                    <p>PostgreSQL is vereist. In de meegeleverde Docker-stack is de host <code>postgres</code>. Gebruik de gebruikersnaam en het wachtwoord van die databasecontainer.</p>
                    <label for="db_host">Databasehost</label>
                    <input id="db_host" name="db_host" value="{{ old('db_host', 'postgres') }}" required maxlength="253" autocomplete="off">
                    <label for="db_port">Poort</label>
                    <input id="db_port" name="db_port" type="number" value="{{ old('db_port', '5432') }}" required min="1" max="65535">
                    <label for="db_database">Databasenaam (bestaande database zonder tabellen)</label>
                    <input id="db_database" name="db_database" value="{{ old('db_database', 'fotoarchief') }}" required maxlength="63">
                    <label for="db_username">Databasegebruiker</label>
                    <input id="db_username" name="db_username" value="{{ old('db_username', 'fotoarchief') }}" required maxlength="63" autocomplete="off">
                    <label for="db_password">Databasewachtwoord</label>
                    <input id="db_password" name="db_password" type="password" required maxlength="1024" autocomplete="off">
                    <label for="db_sslmode">Databaseverbinding</label>
                    <select id="db_sslmode" name="db_sslmode">
                        @foreach(['prefer' => 'TLS indien beschikbaar (intern Docker-netwerk)', 'require' => 'TLS verplicht', 'verify-full' => 'TLS met certificaat- en hostcontrole', 'disable' => 'Zonder TLS (alleen vertrouwd lokaal netwerk)'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('db_sslmode', 'prefer') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </fieldset>
                <fieldset class="card">
                    <legend>Private opslag</legend>
                    <label for="disk">Opslagtype</label>
                    <select id="disk" name="disk">
                        <option value="local" @selected(old('disk', 'local') === 'local')>Lokale private opslag</option>
                        <option value="s3" @selected(old('disk') === 's3')>S3-compatible objectopslag</option>
                    </select>
                    <p>Lokaal gebruikt <code>storage/app/private</code>, buiten de webroot. In Docker staat dit op het persistente opslagvolume. Voor een groot archief adviseren we S3 met een private bucket.</p>
                    <h3>Alleen invullen voor S3</h3>
                    <label for="endpoint">S3-endpoint</label>
                    <input id="endpoint" name="endpoint" type="url" value="{{ old('endpoint') }}" placeholder="https://s3.example.org" maxlength="500">
                    <label for="region">Regio</label>
                    <input id="region" name="region" value="{{ old('region') }}" maxlength="100">
                    <label for="bucket">Private bucket</label>
                    <input id="bucket" name="bucket" value="{{ old('bucket') }}" maxlength="100">
                    <label for="access_key">Access key</label>
                    <input id="access_key" name="access_key" type="password" autocomplete="off" maxlength="1024">
                    <label for="secret_key">Secret key</label>
                    <input id="secret_key" name="secret_key" type="password" autocomplete="off" maxlength="1024">
                    <label class="check"><input name="path_style" type="checkbox" value="1" @checked(old('path_style', '1'))> Path-style URLs (onder andere MinIO)</label>
                    <p>De controle schrijft, leest en verwijdert een klein testobject. Controleer daarnaast bij je provider dat anonieme toegang tot de bucket is geblokkeerd.</p>
                </fieldset>
            </div>
            <fieldset class="card">
                <legend>Eerste beheerder</legend>
                <p>Deze versie gebruikt een lokaal account met gehasht wachtwoord. Passkeys en herstel per e-mail zijn nog niet beschikbaar. Bewaar je inloggegevens in een wachtwoordmanager.</p>
                <div class="grid">
                    <div><label for="name">Naam</label><input id="name" name="name" value="{{ old('name') }}" maxlength="120" autocomplete="name"></div>
                    <div><label for="email">E-mailadres</label><input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="254" autocomplete="username"></div>
                    <div><label for="password">Wachtwoord (minimaal 14 tekens)</label><input id="password" name="password" type="password" minlength="14" maxlength="128" autocomplete="new-password"></div>
                    <div><label for="password_confirmation">Herhaal wachtwoord</label><input id="password_confirmation" name="password_confirmation" type="password" minlength="14" maxlength="128" autocomplete="new-password"></div>
                </div>
            </fieldset>
            <div class="actions">
                <button class="secondary" type="submit" formaction="/setup/check">Alleen verbindingen testen</button>
                <button type="submit">Controleren en installeren</button>
            </div>
            <p>Installeren controleert beide verbindingen opnieuw, maakt tabellen en rollen aan en vergrendelt de wizard. Instellingen worden privaat op deze server opgeslagen.</p>
        </form>
    @endunless
@endsection
