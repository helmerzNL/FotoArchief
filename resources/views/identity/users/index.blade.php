@extends('layouts.app')
@section('title', __('identity.users.title'))
@section('content')
    <section class="card">
        <p class="eyebrow">{{ __('identity.users.eyebrow') }}</p>
        <h1>{{ __('identity.users.heading') }}</h1>
        <p>{{ __('identity.users.intro') }}</p>
        <p>{{ __('identity.users.invitation_intro') }}</p>
        <a class="button" href="{{ route('identity.invitations.create') }}">{{ __('identity.users.new_invitation') }}</a>
    </section>

    <section class="card" data-open-invitations>
        <h2>{{ __('identity.users.open_invitations') }}</h2>
        @forelse($invitations as $invitation)
            <div class="actions">
                <p>
                    <strong>{{ $invitation->isReset() ? __('identity.users.kind_reset') : __('identity.users.kind_new') }}</strong>
                    · {{ $invitation->name }} ({{ $invitation->email }})
                    · {{ __('identity.users.expires', ['expires_at' => $invitation->expires_at->timezone(config('app.timezone'))->format('d-m-Y H:i')]) }}
                    @if($invitation->inviter)
                        · {{ __('identity.users.made_by', ['name' => $invitation->inviter->name]) }}
                    @endif
                </p>
                <form method="post" action="{{ route('identity.invitations.revoke', $invitation) }}">
                    @csrf
                    @method('delete')
                    <button class="secondary" type="submit">{{ __('identity.users.revoke') }}</button>
                </form>
            </div>
        @empty
            <p>{{ __('identity.users.no_open_invitations') }}</p>
        @endforelse
    </section>

    @foreach($users as $user)
        <section class="card">
            <h2>{{ $user->name }}</h2>
            <p><strong>{{ $user->email }}</strong> · {{ $user->is_active ? __('identity.users.active') : __('identity.users.deactivated') }}
                @if($user->roles->contains('key', 'administrator'))
                    · <span class="badge">{{ __('identity.users.administrator') }}</span>
                @endif
            </p>
            <p data-sign-in-methods>
                {{ __('identity.users.sign_in_methods') }}
                @php
                    $methods = [];
                    if ($user->has_password) { $methods[] = __('identity.users.method_password'); }
                    if ($user->passkeys_count > 0) { $methods[] = trans_choice('identity.users.method_passkeys', $user->passkeys_count, ['count' => $user->passkeys_count]); }
                    if ($user->recovery_codes_count > 0) { $methods[] = trans_choice('identity.users.method_recovery_codes', $user->recovery_codes_count, ['count' => $user->recovery_codes_count]); }
                @endphp
                {{ $methods === [] ? __('identity.users.method_none') : implode(', ', $methods) }}
                · {{ __('identity.users.since', ['date' => $user->created_at?->timezone(config('app.timezone'))->format('d-m-Y')]) }}
            </p>
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
                    @if($user->id !== auth()->id())
                        <form method="post" action="{{ route('identity.users.reset', $user) }}" data-confirm="{{ __('identity.users.reset_confirm', ['name' => $user->name]) }}" onsubmit="return window.confirm(this.dataset.confirm);">
                            @csrf
                            <button class="secondary" type="submit">{{ __('identity.users.reset') }}</button>
                        </form>
                    @endif
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
