@extends('layouts.app')
@section('title', 'Inloggen - FotoArchief')
@section('content')
    <section class="card narrow">
        <p class="eyebrow">Beheeromgeving</p>
        <h1>Welkom terug</h1>
        <p>Log in met het account dat je tijdens de installatie hebt aangemaakt.</p>
        <form method="post" action="/login">
            @csrf
            <label for="email">E-mailadres</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username">
            <label for="password">Wachtwoord</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
            <button type="submit">Inloggen</button>
        </form>
    </section>
@endsection
