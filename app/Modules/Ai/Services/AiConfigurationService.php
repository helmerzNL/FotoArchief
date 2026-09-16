<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Models\User;
use App\Modules\Ai\Models\AiSetting;
use Illuminate\Validation\ValidationException;

class AiConfigurationService
{
    public function __construct(private readonly AiProviderConfigService $providerConfigs) {}

    private const BOOL_KEYS = [
        'global_enabled',
        'emergency_stop',
        'image_analysis_enabled',
        'embeddings_enabled',
        'local_provider_enabled',
        'external_processing_allowed',
        'image_analysis_native_consent',
        'embeddings_native_consent',
    ];

    private const INT_KEYS = [
        'max_assets_per_batch',
        'derivative_max_pixels',
        'request_timeout_seconds',
    ];

    private const TEXT_KEYS = [
        'local_endpoint',
        'image_analysis_provider',
        'image_analysis_model',
        'embeddings_provider',
        'embeddings_model',
    ];

    /** Providers that can perform image analysis (capability-checked at dispatch time). */
    public const IMAGE_ANALYSIS_PROVIDERS = ['local', 'external', 'openai', 'anthropic', 'gemini', 'openrouter'];

    /**
     * Embeddings providers restricted to genuinely multimodal / shared-space
     * models: OpenAI embeddings are text-only and Anthropic has no native
     * embeddings API, so neither is offered here.
     */
    public const EMBEDDINGS_PROVIDERS = ['local', 'external', 'gemini', 'openrouter'];

    public const NATIVE_PROVIDERS = ['openai', 'anthropic', 'gemini', 'openrouter'];

    /**
     * @return array<string, bool|int|string|null>
     */
    public function effective(): array
    {
        $settings = config('ai.defaults', []);
        foreach (AiSetting::query()->get() as $row) {
            $value = is_array($row->value) ? ($row->value['value'] ?? null) : null;
            $settings[$row->key] = $value;
        }

        $settings['active'] = (bool) ($settings['global_enabled'] ?? false)
            && ! (bool) ($settings['emergency_stop'] ?? false);
        $settings['local_ready'] = (bool) ($settings['active'] ?? false)
            && (bool) ($settings['local_provider_enabled'] ?? false)
            && is_string($settings['local_endpoint'] ?? null)
            && $settings['local_endpoint'] !== '';
        $external = $this->providerConfigs->status('external');
        $settings['external_provider_enabled'] = $external['enabled'];
        $settings['external_endpoint'] = $external['endpoint'];
        $settings['provider_region'] = $external['provider_region'];
        $settings['retention_notice'] = $external['retention_notice'];
        $settings['monthly_external_budget_cents'] = $external['monthly_budget_cents'];
        $settings['external_configured'] = $this->providerConfigured($external, true);
        $settings['external_ready'] = (bool) ($settings['active'] ?? false)
            && $external['enabled']
            && (bool) ($settings['external_processing_allowed'] ?? false)
            && $settings['external_configured'];

        foreach (self::NATIVE_PROVIDERS as $provider) {
            $providerStatus = $this->providerConfigs->status($provider);
            $settings["{$provider}_provider_enabled"] = $providerStatus['enabled'];
            $settings["{$provider}_configured"] = $this->providerConfigured($providerStatus);
            $settings["{$provider}_ready"] = (bool) ($settings['active'] ?? false)
                && $providerStatus['enabled']
                && $settings["{$provider}_configured"];
        }

        $imageProvider = (string) ($settings['image_analysis_provider'] ?? '');
        if (in_array($imageProvider, AiProviderConfigService::PROVIDERS, true)) {
            $settings['image_analysis_model'] = $this->providerConfigs->status($imageProvider)['vision_model'];
        }
        $embeddingProvider = (string) ($settings['embeddings_provider'] ?? '');
        if (in_array($embeddingProvider, AiProviderConfigService::PROVIDERS, true)) {
            $settings['embeddings_model'] = $this->providerConfigs->status($embeddingProvider)['embedding_model'];
        }

        $settings['image_analysis_ready'] = $this->capabilityReady(
            $settings,
            (string) ($settings['image_analysis_provider'] ?? ''),
            (bool) ($settings['image_analysis_native_consent'] ?? false),
            (string) ($settings['image_analysis_model'] ?? ''),
        );
        $settings['embeddings_ready'] = $this->capabilityReady(
            $settings,
            (string) ($settings['embeddings_provider'] ?? ''),
            (bool) ($settings['embeddings_native_consent'] ?? false),
            (string) ($settings['embeddings_model'] ?? ''),
        );

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function capabilityReady(array $settings, string $provider, bool $nativeConsent, string $model): bool
    {
        if ($provider === '') {
            return false;
        }
        if ($provider === 'local') {
            return (bool) ($settings['local_ready'] ?? false);
        }
        if ($provider === 'external') {
            return (bool) ($settings['external_ready'] ?? false);
        }
        if (! in_array($provider, self::NATIVE_PROVIDERS, true)) {
            return false;
        }

        return $nativeConsent && $model !== '' && (bool) ($settings["{$provider}_ready"] ?? false);
    }

    /** @param array<string, mixed> $config */
    private function providerConfigured(array $config, bool $requiresEndpoint = false): bool
    {
        return ($config['has_api_key'] ?? false) === true
            && (int) ($config['monthly_budget_cents'] ?? 0) > 0
            && (! $requiresEndpoint || (is_string($config['endpoint'] ?? null) && $config['endpoint'] !== ''));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, bool|int|string|null>
     */
    public function update(array $input, User $user): array
    {
        $values = $this->normalize($input);
        $this->validatePrivacy($values);

        foreach ($values as $key => $value) {
            AiSetting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'value' => ['value' => $value],
                    'updated_by_user_id' => $user->id,
                ],
            );
        }

        return $this->effective();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, bool|int|string|null>
     */
    private function normalize(array $input): array
    {
        $values = [];
        foreach (self::BOOL_KEYS as $key) {
            $values[$key] = filter_var($input[$key] ?? false, FILTER_VALIDATE_BOOL);
        }
        foreach (self::INT_KEYS as $key) {
            $values[$key] = (int) ($input[$key] ?? config("ai.defaults.{$key}", 0));
        }
        foreach (self::TEXT_KEYS as $key) {
            $raw = $input[$key] ?? null;
            $value = is_string($raw) ? trim($raw) : null;
            $values[$key] = $value === '' ? null : $value;
        }

        return $values;
    }

    /**
     * @param  array<string, bool|int|string|null>  $values
     */
    private function validatePrivacy(array $values): void
    {
        $errors = [];
        if ((int) $values['max_assets_per_batch'] < 1 || (int) $values['max_assets_per_batch'] > 25) {
            $errors['max_assets_per_batch'] = 'AI-batches zijn begrensd op 1 tot 25 assets.';
        }
        if ((int) $values['derivative_max_pixels'] < 256 || (int) $values['derivative_max_pixels'] > 1024) {
            $errors['derivative_max_pixels'] = 'AI-afgeleiden moeten tussen 256 en 1024 pixels blijven.';
        }
        if ((int) $values['request_timeout_seconds'] < 5 || (int) $values['request_timeout_seconds'] > 60) {
            $errors['request_timeout_seconds'] = 'AI-provider timeouts moeten tussen 5 en 60 seconden blijven.';
        }
        if ((bool) $values['local_provider_enabled'] && ! $this->isTrustedLocalEndpoint($values['local_endpoint'])) {
            $errors['local_endpoint'] = 'Lokale AI moet een HTTPS-endpoint of localhost/private netwerkendpoint zijn.';
        }
        if ((bool) $values['external_processing_allowed'] && ! $this->providerConfigs->status('external')['enabled']) {
            $errors['external_processing_allowed'] = 'Schakel eerst de externe provider in de providersectie in.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->validateCapability($values, 'image_analysis_enabled', 'image_analysis_provider', 'image_analysis_model', 'image_analysis_native_consent', self::IMAGE_ANALYSIS_PROVIDERS, $errors);
        $this->validateCapability($values, 'embeddings_enabled', 'embeddings_provider', 'embeddings_model', 'embeddings_native_consent', self::EMBEDDINGS_PROVIDERS, $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, bool|int|string|null>  $values
     * @param  list<string>  $allowedProviders
     * @param  array<string, string>  $errors
     */
    private function validateCapability(array $values, string $enabledKey, string $providerKey, string $modelKey, string $consentKey, array $allowedProviders, array &$errors): void
    {
        if (! (bool) ($values[$enabledKey] ?? false)) {
            return;
        }

        $provider = (string) ($values[$providerKey] ?? '');
        if ($provider === '') {
            return;
        }
        if (! in_array($provider, $allowedProviders, true)) {
            $errors[$providerKey] = "Ongeldige provider voor {$providerKey}: {$provider}.";

            return;
        }
        if (in_array($provider, self::NATIVE_PROVIDERS, true)) {
            if (trim((string) ($values[$modelKey] ?? '')) === '') {
                $errors[$modelKey] = 'Kies een model voor de gekozen provider.';
            }
            if (! (bool) ($values[$consentKey] ?? false)) {
                $errors[$consentKey] = 'Native provider gebruik vereist expliciete toestemming per functie.';
            }
            if (! (bool) $this->providerConfigs->status($provider)['enabled']) {
                $errors["{$provider}_provider_enabled"] = 'Schakel de provider eerst in voordat je hem selecteert.';
            }
        }
    }

    private function isTrustedLocalEndpoint(mixed $endpoint): bool
    {
        if (! is_string($endpoint) || $endpoint === '') {
            return false;
        }
        $parts = parse_url($endpoint);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if ($parts['scheme'] === 'https') {
            return true;
        }
        if ($parts['scheme'] !== 'http') {
            return false;
        }

        return $this->isLocalOrPrivateHost((string) $parts['host']);
    }

    private function isLocalOrPrivateHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.local')) {
            return true;
        }
        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip === false) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
