@extends('layouts.app')
@section('title', 'Uitnodigen - FotoArchief')
@section('content')
    <section class="card narrow">
        <p class="eyebrow">Geen mailprovider</p>
        <h1>Gebruiker uitnodigen</h1>
        <p>Maak een eenmalige link. De link wordt alleen direct na aanmaken aan jou getoond.</p>
        <form method="post" action="{{ route('identity.invitations.store') }}">
            @csrf
            <label for="name">Naam</label>
            <input id="name" name="name" value="{{ old('name') }}" required autocomplete="name">
            <label for="email">E-mailadres</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
            <fieldset>
                <legend>Rollen</legend>
                @foreach($roles as $role)
                    <label class="check">
                        <input type="checkbox" name="roles[]" value="{{ $role->id }}" @checked(in_array($role->id, old('roles', []), true))>
                        {{ $role->name }} <small>({{ $role->key }})</small>
                    </label>
                @endforeach
            </fieldset>
            <button type="submit">Uitnodigingslink maken</button>
        </form>
    </section>
@endsection
