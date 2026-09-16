@extends('layouts.app')
@section('title', __('onboarding.setup.title'))
@section('content')
    <p class="eyebrow">{{ __('onboarding.setup.eyebrow') }}</p>
    <h1>{{ __('onboarding.setup.heading') }}</h1>
    <p class="intro">{{ __('onboarding.setup.intro') }}</p>
    <div class="notice">
        {!! __('onboarding.setup.requirements_notice') !!}
    </div>
    <ol class="steps" aria-label="{{ __('onboarding.setup.steps_label') }}">
        <li @if(!$authorized) aria-current="step" @endif>{{ __('onboarding.setup.steps.access') }}</li>
        <li @if($authorized) aria-current="step" @endif>{{ __('onboarding.setup.steps.configure') }}</li>
        <li>{{ __('onboarding.setup.steps.login') }}</li>
    </ol>
    @unless($authorized)
        <section class="card narrow">
            <h2>{{ __('onboarding.setup.owner_only.heading') }}</h2>
            <p>{!! __('onboarding.setup.owner_only.prepare') !!}</p>
            <p>{!! __('onboarding.setup.owner_only.hosting') !!}</p>
            <form method="post" action="/setup/unlock">
                @csrf
                <label for="code">{{ __('onboarding.setup.owner_only.code') }}</label>
                <input id="code" name="code" type="password" autocomplete="off" required maxlength="100">
                <button type="submit">{{ __('onboarding.setup.owner_only.submit') }}</button>
            </form>
        </section>
    @else
        @if($resuming)
            <div class="notice">{{ __('onboarding.setup.resume_notice') }}</div>
        @endif
        <p>{{ __('onboarding.setup.authorized_notice') }}</p>
        <form method="post" action="/setup/complete">
            @csrf
            <div class="grid">
                <fieldset class="card">
                    <legend>{{ __('onboarding.setup.database.legend') }}</legend>
                    <p>{!! __('onboarding.setup.database.help') !!}</p>
                    <label for="db_host">{{ __('onboarding.setup.database.host') }}</label>
                    <input id="db_host" name="db_host" value="{{ old('db_host', 'postgres') }}" required maxlength="253" autocomplete="off">
                    <label for="db_port">{{ __('onboarding.setup.database.port') }}</label>
                    <input id="db_port" name="db_port" type="number" value="{{ old('db_port', '5432') }}" required min="1" max="65535">
                    <label for="db_database">{{ __('onboarding.setup.database.name') }}</label>
                    <input id="db_database" name="db_database" value="{{ old('db_database', 'fotoarchief') }}" required maxlength="63">
                    <label for="db_username">{{ __('onboarding.setup.database.user') }}</label>
                    <input id="db_username" name="db_username" value="{{ old('db_username', 'fotoarchief') }}" required maxlength="63" autocomplete="off">
                    <label for="db_password">{{ __('onboarding.setup.database.password') }}</label>
                    <input id="db_password" name="db_password" type="password" required maxlength="1024" autocomplete="off">
                    <label for="db_sslmode">{{ __('onboarding.setup.database.connection') }}</label>
                    <select id="db_sslmode" name="db_sslmode">
                        @foreach(['prefer' => __('onboarding.setup.database.ssl_modes.prefer'), 'require' => __('onboarding.setup.database.ssl_modes.require'), 'verify-full' => __('onboarding.setup.database.ssl_modes.verify-full'), 'disable' => __('onboarding.setup.database.ssl_modes.disable')] as $value => $label)
                            <option value="{{ $value }}" @selected(old('db_sslmode', 'prefer') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </fieldset>
                <fieldset class="card">
                    <legend>{{ __('onboarding.setup.storage.legend') }}</legend>
                    <label for="disk">{{ __('onboarding.setup.storage.type') }}</label>
                    <select id="disk" name="disk">
                        <option value="local" @selected(old('disk', 'local') === 'local')>{{ __('onboarding.setup.storage.local') }}</option>
                        <option value="s3" @selected(old('disk') === 's3')>{{ __('onboarding.setup.storage.s3') }}</option>
                    </select>
                    <p>{!! __('onboarding.setup.storage.help') !!}</p>
                    <h3>{{ __('onboarding.setup.storage.s3_heading') }}</h3>
                    <label for="endpoint">{{ __('onboarding.setup.storage.endpoint') }}</label>
                    <input id="endpoint" name="endpoint" type="url" value="{{ old('endpoint') }}" placeholder="{{ __('onboarding.setup.storage.endpoint_placeholder') }}" maxlength="500">
                    <label for="region">{{ __('onboarding.setup.storage.region') }}</label>
                    <input id="region" name="region" value="{{ old('region') }}" maxlength="100">
                    <label for="bucket">{{ __('onboarding.setup.storage.bucket') }}</label>
                    <input id="bucket" name="bucket" value="{{ old('bucket') }}" maxlength="100">
                    <label for="access_key">{{ __('onboarding.setup.storage.access_key') }}</label>
                    <input id="access_key" name="access_key" type="password" autocomplete="off" maxlength="1024">
                    <label for="secret_key">{{ __('onboarding.setup.storage.secret_key') }}</label>
                    <input id="secret_key" name="secret_key" type="password" autocomplete="off" maxlength="1024">
                    <label class="check"><input name="path_style" type="checkbox" value="1" @checked(old('path_style', '1'))> {{ __('onboarding.setup.storage.path_style') }}</label>
                    <p>{{ __('onboarding.setup.storage.probe') }}</p>
                </fieldset>
            </div>
            <fieldset class="card">
                <legend>{{ __('onboarding.setup.admin.legend') }}</legend>
                <p>{{ __('onboarding.setup.admin.help') }}</p>
                <div class="grid">
                    <div><label for="name">{{ __('onboarding.setup.admin.name') }}</label><input id="name" name="name" value="{{ old('name') }}" maxlength="120" autocomplete="name"></div>
                    <div><label for="email">{{ __('onboarding.setup.admin.email') }}</label><input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="254" autocomplete="username"></div>
                    <div><label for="password">{{ __('onboarding.setup.admin.password') }}</label><input id="password" name="password" type="password" minlength="14" maxlength="128" autocomplete="new-password"></div>
                    <div><label for="password_confirmation">{{ __('onboarding.setup.admin.password_confirmation') }}</label><input id="password_confirmation" name="password_confirmation" type="password" minlength="14" maxlength="128" autocomplete="new-password"></div>
                </div>
            </fieldset>
            <div class="actions">
                <button class="secondary" type="submit" formaction="/setup/check">{{ __('onboarding.setup.actions.check') }}</button>
                <button type="submit">{{ __('onboarding.setup.actions.complete') }}</button>
            </div>
            <p>{{ __('onboarding.setup.complete_help') }}</p>
        </form>
    @endunless
@endsection
