@extends('layouts.app')
@section('title', __('identity.users.title'))
@section('content')
    <section class="card">
        <p class="eyebrow">{{ __('identity.users.eyebrow') }}</p>
        <h1>{{ __('identity.users.heading') }}</h1>
        <p>{{ __('identity.users.intro') }}</p>
        <a class="button" href="{{ route('identity.invitations.create') }}">{{ __('identity.users.new_invitation') }}</a>
    </section>

    @foreach($users as $user)
        <section class="card">
            <h2>{{ $user->name }}</h2>
            <p><strong>{{ $user->email }}</strong> · {{ $user->is_active ? __('identity.users.active') : __('identity.users.deactivated') }}</p>
            <form method="post" action="{{ route('identity.users.update', $user) }}">
                @csrf
                @method('put')
                <label for="name-{{ $user->id }}">{{ __('identity.common.name') }}</label>
                <input id="name-{{ $user->id }}" name="name" value="{{ old('name', $user->name) }}" required>
                <fieldset>
                    <legend>{{ __('identity.common.roles') }}</legend>
                    @foreach($roles as $role)
                        <label class="check">
                            <input type="checkbox" name="roles[]" value="{{ $role->id }}" @checked($user->roles->contains('id', $role->id))>
                            {{ $role->name }} <small>{{ __('identity.common.role_key', ['key' => $role->key]) }}</small>
                        </label>
                    @endforeach
                </fieldset>
                <button type="submit">{{ __('identity.users.save_and_revoke_sessions') }}</button>
            </form>
            <div class="actions">
                @if($user->is_active)
                    <form method="post" action="{{ route('identity.users.deactivate', $user) }}">
                        @csrf
                        <button class="secondary" type="submit">{{ __('identity.users.deactivate') }}</button>
                    </form>
                @else
                    <form method="post" action="{{ route('identity.users.reactivate', $user) }}">
                        @csrf
                        <button class="secondary" type="submit">{{ __('identity.users.reactivate') }}</button>
                    </form>
                @endif
            </div>
        </section>
    @endforeach
@endsection
