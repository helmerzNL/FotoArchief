@extends('layouts.app')
@section('title', __('identity.invitations.create.title'))
@section('content')
    <section class="card narrow">
        <p class="eyebrow">{{ __('identity.invitations.create.eyebrow') }}</p>
        <h1>{{ __('identity.invitations.create.heading') }}</h1>
        <p>{{ __('identity.invitations.create.intro') }}</p>
        <form method="post" action="{{ route('identity.invitations.store') }}">
            @csrf
            <label for="name">{{ __('identity.common.name') }}</label>
            <input id="name" name="name" value="{{ old('name') }}" required autocomplete="name">
            <label for="email">{{ __('identity.common.email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
            <fieldset>
                <legend>{{ __('identity.common.roles') }}</legend>
                @foreach($roles as $role)
                    <label class="check">
                        <input type="checkbox" name="roles[]" value="{{ $role->id }}" @checked(in_array($role->id, old('roles', []), true))>
                        {{ $role->name }} <small>{{ __('identity.common.role_key', ['key' => $role->key]) }}</small>
                    </label>
                @endforeach
            </fieldset>
            <button type="submit">{{ __('identity.invitations.create.submit') }}</button>
        </form>
    </section>
@endsection
