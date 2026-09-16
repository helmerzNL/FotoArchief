@extends('layouts.app')
@section('title', 'Identiteit - FotoArchief')
@section('content')
    <section class="card">
        <p class="eyebrow">Identiteit en toegang</p>
        <h1>Gebruikers</h1>
        <p>Beheer rollen, trek sessies direct in of deactiveer accounts. Minstens één actieve beheerder blijft verplicht.</p>
        <a class="button" href="{{ route('identity.invitations.create') }}">Nieuwe uitnodiging</a>
    </section>

    @foreach($users as $user)
        <section class="card">
            <h2>{{ $user->name }}</h2>
            <p><strong>{{ $user->email }}</strong> · {{ $user->is_active ? 'Actief' : 'Gedeactiveerd' }}</p>
            <form method="post" action="{{ route('identity.users.update', $user) }}">
                @csrf
                @method('put')
                <label for="name-{{ $user->id }}">Naam</label>
                <input id="name-{{ $user->id }}" name="name" value="{{ old('name', $user->name) }}" required>
                <fieldset>
                    <legend>Rollen</legend>
                    @foreach($roles as $role)
                        <label class="check">
                            <input type="checkbox" name="roles[]" value="{{ $role->id }}" @checked($user->roles->contains('id', $role->id))>
                            {{ $role->name }} <small>({{ $role->key }})</small>
                        </label>
                    @endforeach
                </fieldset>
                <button type="submit">Opslaan en sessies intrekken</button>
            </form>
            <div class="actions">
                @if($user->is_active)
                    <form method="post" action="{{ route('identity.users.deactivate', $user) }}">
                        @csrf
                        <button class="secondary" type="submit">Deactiveren</button>
                    </form>
                @else
                    <form method="post" action="{{ route('identity.users.reactivate', $user) }}">
                        @csrf
                        <button class="secondary" type="submit">Heractiveren</button>
                    </form>
                @endif
            </div>
        </section>
    @endforeach
@endsection
