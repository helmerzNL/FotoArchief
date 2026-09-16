@extends('layouts.app')
@section('title', 'Uitnodiging accepteren - FotoArchief')
@section('content')
    <section class="card narrow">
        <p class="eyebrow">Uitnodiging</p>
        <h1>Account activeren</h1>
        <p>Je activeert een account voor <strong>{{ $invitation->email }}</strong>. Deze link kan één keer worden gebruikt en verloopt op {{ $invitation->expires_at->timezone(config('app.timezone'))->format('d-m-Y H:i') }}.</p>
        <form method="post" action="{{ route('identity.invitations.complete', ['token' => $token]) }}">
            @csrf
            <label for="password">Tijdelijk wachtwoord</label>
            <input id="password" name="password" type="password" required minlength="14" maxlength="128" autocomplete="new-password">
            <label for="password_confirmation">Herhaal wachtwoord</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required minlength="14" maxlength="128" autocomplete="new-password">
            <button type="submit">Account activeren</button>
        </form>
    </section>
@endsection
