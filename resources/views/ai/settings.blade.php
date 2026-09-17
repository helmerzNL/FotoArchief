@extends('layouts.app')

@section('title', __('ai.settings.title'))

@section('content')
    <h1>{{ __('ai.settings.title') }}</h1>
    <p>{{ __('ai.settings.intro') }}</p>

    @include('operations._nav')

    @if(session('status'))
        <div class="card" role="status">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="card" role="alert">
            <strong>{{ __('ai.settings.validation_failed') }}</strong>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="card">
        <h2>{{ __('ai.settings.status.title') }}</h2>
        <dl>
            <dt>{{ __('ai.settings.status.active') }}</dt>
            <dd>{{ $settings['active'] ? __('ai.common.yes') : __('ai.common.no') }}</dd>
            <dt>{{ __('ai.settings.status.local_ready') }}</dt>
            <dd>{{ $settings['local_ready'] ? __('ai.common.yes') : __('ai.common.no') }}</dd>
            <dt>{{ __('ai.settings.status.external_ready') }}</dt>
            <dd>{{ $settings['external_ready'] ? __('ai.common.yes') : __('ai.common.no') }}</dd>
            @foreach(\App\Modules\Ai\Services\AiConfigurationService::NATIVE_PROVIDERS as $native)
                <dt>{{ __('ai.settings.status.native_ready', ['provider' => ucfirst($native)]) }}</dt>
                <dd>{{ $settings["{$native}_configured"] ? __('ai.common.yes') : __('ai.common.no') }} / {{ $settings["{$native}_ready"] ? __('ai.common.yes') : __('ai.common.no') }}</dd>
            @endforeach
            <dt>{{ __('ai.settings.status.image_ready') }}</dt>
            <dd>{{ $settings['image_analysis_ready'] ? __('ai.common.yes') : __('ai.common.no') }}</dd>
            <dt>{{ __('ai.settings.status.embeddings_ready') }}</dt>
            <dd>{{ $settings['embeddings_ready'] ? __('ai.common.yes') : __('ai.common.no') }}</dd>
            <dt>{{ __('ai.settings.status.pgvector_available') }}</dt>
            <dd>{{ $settings['pgvector_available'] ? __('ai.common.yes') : __('ai.common.no') }}</dd>
        </dl>
        <p>{{ __('ai.settings.status.configured_notice') }}</p>
        @unless($settings['pgvector_available'])
            <p role="alert">{{ __('ai.settings.status.pgvector_unavailable') }}</p>
        @endunless
    </section>

    <section class="card">
        <h2>{{ __('ai.settings.providers.title') }}</h2>
        <p>{{ __('ai.settings.providers.intro') }}</p>
        @foreach($providerStatuses as $provider)
            <article class="card">
                <h3>{{ ucfirst($provider['provider']) }}</h3>
                <dl>
                    <dt>{{ __('ai.settings.providers.key_status') }}</dt><dd>{{ $provider['has_api_key'] ? __('ai.settings.status.key_set') : __('ai.settings.status.key_missing') }}</dd>
                    <dt>{{ $provider['provider'] === 'external' ? __('ai.settings.providers.endpoint') : __('ai.settings.providers.fixed_base_url') }}</dt><dd><code>{{ $provider['base_url'] ?: __('ai.common.unset') }}</code></dd>
                    @if($provider['api_version'])
                        <dt>{{ __('ai.settings.providers.fixed_api_version') }}</dt><dd>{{ $provider['api_version'] }}</dd>
                    @endif
                    @if($provider['embedding_model_allowlist'])
                        <dt>{{ __('ai.settings.providers.openrouter_allowlist') }}</dt><dd>{{ implode(', ', $provider['embedding_model_allowlist']) }}</dd>
                    @endif
                </dl>
                <form method="POST" action="{{ route('admin.operations.ai.provider.update', $provider['provider']) }}">
                    @csrf
                    <label><input type="checkbox" name="enabled" value="1" @checked($provider['enabled'])> {{ __('ai.settings.providers.allow_provider') }}</label>
                    <label>{{ __('ai.settings.providers.vision_model') }} <input type="text" name="vision_model" value="{{ $provider['vision_model'] }}"></label>
                    <label>{{ __('ai.settings.providers.embedding_model') }} <input type="text" name="embedding_model" value="{{ $provider['embedding_model'] }}"></label>
                    @if($provider['provider'] === 'external')
                        <label>{{ __('ai.settings.providers.public_https_endpoint') }} <input type="url" name="endpoint" value="{{ $provider['endpoint'] }}" required></label>
                        <label>{{ __('ai.settings.providers.provider_region') }} <input type="text" name="provider_region" value="{{ $provider['provider_region'] }}" required></label>
                        <label>{{ __('ai.settings.providers.retention_notice') }} <textarea name="retention_notice" rows="3" required>{{ $provider['retention_notice'] }}</textarea></label>
                    @endif
                    <label>{{ __('ai.settings.providers.image_cost') }} <input type="number" min="0" name="cost_cents_per_image" value="{{ $provider['cost_cents_per_image'] }}"></label>
                    <label>{{ __('ai.settings.providers.embedding_cost') }} <input type="number" min="0" name="cost_cents_per_embedding" value="{{ $provider['cost_cents_per_embedding'] }}"></label>
                    <label>{{ __('ai.settings.providers.monthly_budget') }} <input type="number" min="0" name="monthly_budget_cents" value="{{ $provider['monthly_budget_cents'] }}"></label>
                    <button type="submit">{{ __('ai.settings.providers.save') }}</button>
                </form>
                <form method="POST" action="{{ route('admin.operations.ai.provider.key.set', $provider['provider']) }}">
                    @csrf
                    <label>{{ __('ai.settings.providers.set_key') }} <input type="password" name="api_key" autocomplete="new-password" required></label>
                    <button type="submit">{{ __('ai.settings.providers.save_key') }}</button>
                </form>
                @if($provider['has_api_key'])
                    <form method="POST" action="{{ route('admin.operations.ai.provider.key.delete', $provider['provider']) }}">
                        @csrf
                        @method('DELETE')
                        <label><input type="checkbox" name="confirm_delete" value="1" required> {{ __('ai.settings.providers.delete_confirm') }}</label>
                        <button type="submit">{{ __('ai.settings.providers.delete_key') }}</button>
                    </form>
                @endif
            </article>
        @endforeach
    </section>

    @if(session('connection_test'))
        @php
            $test = session('connection_test');
        @endphp
        <section class="card" role="status">
            <h2>{{ __('ai.settings.connection.result_title', ['provider' => ucfirst($test['provider']), 'capability' => $test['capability'] === 'embeddings' ? __('ai.common.embeddings') : __('ai.common.image_analysis')]) }}</h2>
            <p>{{ $test['message'] }}</p>
            @if($test['status'] === 'ok' && array_key_exists('models_count', $test))
                <p>{{ __('ai.settings.connection.models_visible', ['count' => $test['models_count'], 'model' => $test['model_configured'], 'found' => $test['model_found'] ? __('ai.common.yes') : __('ai.common.no')]) }}</p>
            @endif
        </section>
    @endif

    <section class="card">
        <h2>{{ __('ai.settings.connection.title') }}</h2>
        <p>{{ __('ai.settings.connection.intro') }}</p>
        <form method="POST" action="{{ route('admin.operations.ai.test-connection') }}">
            @csrf
            <label>{{ __('ai.common.provider') }}
                <select name="test_provider">
                    @foreach(\App\Modules\Ai\Services\AiConfigurationService::IMAGE_ANALYSIS_PROVIDERS as $providerOption)
                        <option value="{{ $providerOption }}">{{ ucfirst($providerOption) }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ __('ai.common.capability') }}
                <select name="test_capability">
                    <option value="image_analysis">{{ __('ai.common.image_analysis') }}</option>
                    <option value="embeddings">{{ __('ai.common.embeddings') }}</option>
                </select>
            </label>
            <button type="submit">{{ __('ai.settings.connection.submit') }}</button>
        </form>
    </section>

    <form method="POST" action="{{ route('admin.operations.ai.update') }}" class="card">
        @csrf
        <h2>{{ __('ai.settings.features.title') }}</h2>
        @php
            $featureLabels = [
                'global_enabled' => __('ai.settings.features.global_enabled'),
                'emergency_stop' => __('ai.settings.features.emergency_stop'),
                'image_analysis_enabled' => __('ai.settings.features.image_analysis_enabled'),
                'embeddings_enabled' => __('ai.settings.features.embeddings_enabled'),
                'local_provider_enabled' => __('ai.settings.features.local_provider_enabled'),
                'external_processing_allowed' => __('ai.settings.features.external_processing_allowed'),
            ];
        @endphp
        @foreach($featureLabels as $field => $label)
            <label style="display:block;margin:0.5rem 0;">
                <input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $settings[$field]) === true || old($field, $settings[$field]) === '1')>
                {{ $label }}
            </label>
        @endforeach
        <p>{{ __('ai.settings.features.native_notice') }}</p>

        <h2>{{ __('ai.settings.image.title') }}</h2>
        <label>{{ __('ai.settings.image.provider') }}
            <select name="image_analysis_provider">
                <option value="">{{ __('ai.common.none') }}</option>
                @foreach(\App\Modules\Ai\Services\AiConfigurationService::IMAGE_ANALYSIS_PROVIDERS as $provider)
                    <option value="{{ $provider }}" @selected(old('image_analysis_provider', $settings['image_analysis_provider']) === $provider)>{{ ucfirst($provider) }}</option>
                @endforeach
            </select>
        </label>
        <p>{{ __('ai.settings.image.database_model_notice') }}</p>
        <label>{{ __('ai.settings.image.local_model') }} <input type="text" name="image_analysis_model" value="{{ old('image_analysis_model', $settings['image_analysis_model']) }}"></label>
        <label style="display:block;margin:0.5rem 0;">
            <input type="checkbox" name="image_analysis_native_consent" value="1" @checked(old('image_analysis_native_consent', $settings['image_analysis_native_consent']) === true || old('image_analysis_native_consent', $settings['image_analysis_native_consent']) === '1')>
            {{ __('ai.settings.image.native_consent') }}
        </label>

        <h2>{{ __('ai.settings.embeddings.title') }}</h2>
        <p>{{ __('ai.settings.embeddings.multimodal_notice') }}</p>
        <label>{{ __('ai.settings.embeddings.provider') }}
            <select name="embeddings_provider">
                <option value="">{{ __('ai.common.none') }}</option>
                @foreach(\App\Modules\Ai\Services\AiConfigurationService::EMBEDDINGS_PROVIDERS as $provider)
                    <option value="{{ $provider }}" @selected(old('embeddings_provider', $settings['embeddings_provider']) === $provider)>{{ ucfirst($provider) }}</option>
                @endforeach
            </select>
        </label>
        <p>{{ __('ai.settings.embeddings.database_model_notice') }}</p>
        <label>{{ __('ai.settings.embeddings.local_model') }} <input type="text" name="embeddings_model" value="{{ old('embeddings_model', $settings['embeddings_model']) }}"></label>
        <label style="display:block;margin:0.5rem 0;">
            <input type="checkbox" name="embeddings_native_consent" value="1" @checked(old('embeddings_native_consent', $settings['embeddings_native_consent']) === true || old('embeddings_native_consent', $settings['embeddings_native_consent']) === '1')>
            {{ __('ai.settings.embeddings.native_consent') }}
        </label>
        <p>{{ __('ai.settings.embeddings.model_change_notice') }}</p>

        <h2>{{ __('ai.settings.endpoints.title') }}</h2>
        <label>{{ __('ai.settings.endpoints.local_endpoint') }}
            <input type="url" name="local_endpoint" value="{{ old('local_endpoint', $settings['local_endpoint']) }}" placeholder="{{ __('ai.settings.endpoints.placeholder') }}">
        </label>

        <h2>{{ __('ai.settings.limits.title') }}</h2>
        <label>{{ __('ai.settings.limits.max_assets') }}
            <input type="number" min="1" max="25" name="max_assets_per_batch" value="{{ old('max_assets_per_batch', $settings['max_assets_per_batch']) }}">
        </label>
        <label>{{ __('ai.settings.limits.max_derivative_edge') }}
            <input type="number" min="256" max="1024" name="derivative_max_pixels" value="{{ old('derivative_max_pixels', $settings['derivative_max_pixels']) }}">
        </label>
        <label>{{ __('ai.settings.limits.timeout') }}
            <input type="number" min="5" max="60" name="request_timeout_seconds" value="{{ old('request_timeout_seconds', $settings['request_timeout_seconds']) }}">
        </label>
        <p>{{ __('ai.settings.limits.key_notice') }}</p>
        <button type="submit">{{ __('ai.settings.save') }}</button>
    </form>

    <form method="POST" action="{{ route('admin.operations.ai.analyze') }}" class="card">
        @csrf
        <h2>{{ __('ai.settings.image.dispatch_title') }}</h2>
        <p>{!! __('ai.settings.image.dispatch_intro', ['runs_link' => '<a href="'.e(route('admin.operations.runs.index')).'">'.e(__('ai.settings.image.runs_page')).'</a>']) !!}</p>
        <label>{{ __('ai.settings.batch.references') }}
            <textarea name="asset_ids" rows="3" placeholder="{{ __('ai.settings.batch.references_placeholder') }}">{{ old('asset_ids') }}</textarea>
        </label>
        <p>{{ __('ai.settings.batch.references_help') }}</p>
        <label>{{ __('ai.common.provider') }}
            <select name="provider">
                @foreach(\App\Modules\Ai\Services\AiConfigurationService::IMAGE_ANALYSIS_PROVIDERS as $provider)
                    <option value="{{ $provider }}" @selected($settings['image_analysis_provider'] === $provider)>{{ ucfirst($provider) }}</option>
                @endforeach
            </select>
        </label>
        @unless($settings['image_analysis_ready'])
            <p role="alert">{{ __('ai.settings.image.not_ready') }}</p>
        @endunless
        <button type="submit" @disabled(! $settings['image_analysis_ready'])>{{ __('ai.settings.image.submit') }}</button>
    </form>

    <form method="POST" action="{{ route('admin.operations.ai.index') }}" class="card">
        @csrf
        <h2>{{ __('ai.settings.embeddings.dispatch_title') }}</h2>
        <p>{{ __('ai.settings.embeddings.dispatch_intro') }}</p>
        <label>{{ __('ai.settings.batch.references') }}
            <textarea name="asset_ids" rows="3" placeholder="{{ __('ai.settings.batch.references_placeholder') }}">{{ old('asset_ids') }}</textarea>
        </label>
        <p>{{ __('ai.settings.batch.references_help') }}</p>
        <label>{{ __('ai.common.provider') }}
            <select name="provider">
                @foreach(\App\Modules\Ai\Services\AiConfigurationService::EMBEDDINGS_PROVIDERS as $provider)
                    <option value="{{ $provider }}" @selected($settings['embeddings_provider'] === $provider)>{{ ucfirst($provider) }}</option>
                @endforeach
            </select>
        </label>
        @unless($settings['embeddings_ready'])
            <p role="alert">{{ __('ai.settings.embeddings.not_ready') }}</p>
        @endunless
        <button type="submit" @disabled(! $settings['embeddings_ready'])>{{ __('ai.settings.embeddings.submit') }}</button>
    </form>
@endsection
