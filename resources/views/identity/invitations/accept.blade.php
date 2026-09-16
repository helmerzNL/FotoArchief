@extends('layouts.app')
@section('title', __('identity.invitations.accept.title'))
@section('content')
    <section class="card narrow">
        <p class="eyebrow">{{ __('identity.invitations.accept.eyebrow') }}</p>
        <h1>{{ __('identity.invitations.accept.heading') }}</h1>
        <p>{{ __('identity.invitations.accept.account_for') }} <strong>{{ $invitation->email }}</strong>. {{ __('identity.invitations.accept.expires_at', ['expires_at' => $invitation->expires_at->timezone(config('app.timezone'))->format('d-m-Y H:i')]) }}</p>
        <form method="post" action="{{ route('identity.invitations.complete', ['token' => $token]) }}">
            @csrf
            <label for="password">{{ __('identity.invitations.accept.password') }}</label>
            <input id="password" name="password" type="password" required minlength="14" maxlength="128" autocomplete="new-password">
            <label for="password_confirmation">{{ __('identity.invitations.accept.password_confirmation') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required minlength="14" maxlength="128" autocomplete="new-password">
            <button type="submit">{{ __('identity.invitations.accept.submit') }}</button>
        </form>
    </section>
@endsection
