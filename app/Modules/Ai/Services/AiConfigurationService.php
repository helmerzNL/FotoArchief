<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Models\User;
use App\Modules\Ai\Models\AiSetting;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class AiConfigurationService
{
    private const BOOL_KEYS = [
        'global_enabled',
        'emergency_stop',
        'image_analysis_enabled',
        'embeddings_enabled',
        'local_provider_enabled',
        'external_provider_enabled',
        'external_processing_allowed',
    ];

    private const INT_KEYS = [
        'max_assets_per_batch',
        'derivative_max_pixels',
        'request_timeout_seconds',
        'monthly_external_budget_cents',
    ];

    private const TEXT_KEYS = [
        'local_endpoint',
        'external_endpoint',
        'provider_region',
        'retention_notice',
    ];

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
        $settings['external_ready'] = (bool) ($settings['active'] ?? false)
            && (bool) ($settings['external_provider_enabled'] ?? false)
            && (bool) ($settings['external_processing_allowed'] ?? false)
            && (int) ($settings['monthly_external_budget_cents'] ?? 0) > 0
            && is_string($settings['external_endpoint'] ?? null)
            && $settings['external_endpoint'] !== '';

        return $settings;
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
        if ((int) $values['monthly_external_budget_cents'] < 0) {
            $errors['monthly_external_budget_cents'] = 'Extern AI-budget kan niet negatief zijn.';
        }

        if ((bool) $values['local_provider_enabled'] && ! $this->isTrustedLocalEndpoint($values['local_endpoint'])) {
            $errors['local_endpoint'] = 'Lokale AI moet een HTTPS-endpoint of localhost/private netwerkendpoint zijn.';
        }
        if ((bool) $values['external_provider_enabled']) {
            if (! $this->isTrustedExternalEndpoint($values['external_endpoint'])) {
                $errors['external_endpoint'] = 'Externe AI vereist een publiek HTTPS-endpoint; localhost en private IP-ranges zijn geblokkeerd.';
            }
            if (! (bool) $values['external_processing_allowed']) {
                $errors['external_processing_allowed'] = 'Externe verwerking vereist expliciete toestemming voor gegevensscope, regio/retentie en kosten.';
            }
            if ((int) $values['monthly_external_budget_cents'] < 1) {
                $errors['monthly_external_budget_cents'] = 'Externe AI vereist een expliciet budget boven nul.';
            }
            foreach (['provider_region', 'retention_notice'] as $key) {
                if (! is_string(Arr::get($values, $key)) || trim((string) $values[$key]) === '') {
                    $errors[$key] = 'Externe AI vereist providerregio en retentie/training-notitie.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
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

    private function isTrustedExternalEndpoint(mixed $endpoint): bool
    {
        if (! is_string($endpoint) || $endpoint === '') {
            return false;
        }
        $parts = parse_url($endpoint);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
            return false;
        }

        return ! $this->isLocalOrPrivateHost((string) $parts['host']);
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
