<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Models\User;
use App\Modules\Ai\Models\AiProviderConfig;
use App\Modules\Ai\Services\Native\AbstractNativeProvider;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AiProviderConfigService
{
    public const PROVIDERS = ['external', 'openai', 'anthropic', 'gemini', 'openrouter'];

    /**
     * @return array<string, mixed>
     */
    public function status(string $provider): array
    {
        $row = $this->row($provider);

        try {
            $hasApiKey = $row instanceof AiProviderConfig && $row->decryptedApiKey() !== null;
        } catch (DecryptException $exception) {
            throw new \RuntimeException("De API-sleutel voor {$provider} kan niet worden ontsleuteld. Controleer APP_KEY.", 0, $exception);
        }

        return [
            'provider' => $provider,
            'enabled' => $row instanceof AiProviderConfig ? (bool) $row->enabled : false,
            'has_api_key' => $hasApiKey,
            'vision_model' => $row?->vision_model,
            'embedding_model' => $row?->embedding_model,
            'cost_cents_per_image' => $row instanceof AiProviderConfig ? (int) $row->cost_cents_per_image : 0,
            'cost_cents_per_embedding' => $row instanceof AiProviderConfig ? (int) $row->cost_cents_per_embedding : 0,
            'monthly_budget_cents' => $row instanceof AiProviderConfig ? (int) $row->monthly_budget_cents : 0,
            'endpoint' => $row?->endpoint,
            'provider_region' => $row?->provider_region,
            'retention_notice' => $row?->retention_notice,
            'base_url' => $provider === 'external' ? $row?->endpoint : AbstractNativeProvider::officialBaseUrl($provider),
            'api_version' => $provider === 'external' ? null : AbstractNativeProvider::officialApiVersion($provider),
            'embedding_model_allowlist' => $provider === 'external' ? [] : AbstractNativeProvider::embeddingModelAllowlist($provider),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function runtime(string $provider): array
    {
        $row = $this->row($provider);
        if (! $row instanceof AiProviderConfig) {
            throw new \RuntimeException("AI-providerconfiguratie ontbreekt voor {$provider}.");
        }

        try {
            $apiKey = $row->decryptedApiKey();
        } catch (DecryptException $exception) {
            throw new \RuntimeException("De API-sleutel voor {$provider} kan niet worden ontsleuteld. Controleer APP_KEY.", 0, $exception);
        }
        if ($provider === 'external' && ! $this->isPublicHttpsEndpoint($row->endpoint)) {
            throw new \RuntimeException('Het externe AI-endpoint is geen publiek HTTPS-endpoint.');
        }

        return [
            'enabled' => $row->enabled,
            'api_key' => $apiKey,
            'vision_model' => $row->vision_model,
            'embedding_model' => $row->embedding_model,
            'endpoint' => $row->endpoint,
            'provider_region' => $row->provider_region,
            'retention_notice' => $row->retention_notice,
            'cost_cents_per_image' => $row->cost_cents_per_image,
            'cost_cents_per_embedding' => $row->cost_cents_per_embedding,
            'monthly_budget_cents' => $row->monthly_budget_cents,
            'base_url' => $provider === 'external' ? $row->endpoint : AbstractNativeProvider::officialBaseUrl($provider),
            'api_version' => $provider === 'external' ? null : AbstractNativeProvider::officialApiVersion($provider),
            'embedding_model_allowlist' => $provider === 'external' ? [] : AbstractNativeProvider::embeddingModelAllowlist($provider),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(string $provider, array $input, ?User $user = null): void
    {
        $this->assertProvider($provider);
        $data = [
            'enabled' => (bool) ($input['enabled'] ?? false),
            'vision_model' => $this->nullableText($input['vision_model'] ?? null),
            'embedding_model' => $this->nullableText($input['embedding_model'] ?? null),
            'endpoint' => $this->nullableText($input['endpoint'] ?? null),
            'provider_region' => $this->nullableText($input['provider_region'] ?? null),
            'retention_notice' => $this->nullableText($input['retention_notice'] ?? null),
            'cost_cents_per_image' => $this->nonNegativeInt($input['cost_cents_per_image'] ?? 0),
            'cost_cents_per_embedding' => $this->nonNegativeInt($input['cost_cents_per_embedding'] ?? 0),
            'monthly_budget_cents' => $this->nonNegativeInt($input['monthly_budget_cents'] ?? 0),
        ];
        $allowlist = $provider === 'external' ? [] : AbstractNativeProvider::embeddingModelAllowlist($provider);
        if ($provider === 'openrouter' && $data['embedding_model'] !== null && ! in_array($data['embedding_model'], $allowlist, true)) {
            throw ValidationException::withMessages(['embedding_model' => 'OpenRouter-embeddings moeten op de vaste multimodale allowlist staan.']);
        }

        if ($provider === 'external' && $data['enabled']) {
            if (! $this->isPublicHttpsEndpoint($data['endpoint'])) {
                throw ValidationException::withMessages(['endpoint' => 'Externe AI vereist een publiek HTTPS-endpoint.']);
            }
            foreach (['provider_region', 'retention_notice'] as $field) {
                if (! is_string($data[$field]) || $data[$field] === '') {
                    throw ValidationException::withMessages([$field => 'Externe AI vereist regio- en retentieinformatie.']);
                }
            }
        }

        $row = AiProviderConfig::query()->firstOrCreate(['provider' => $provider]);
        $data['updated_by_user_id'] = $user?->id;
        $row->forceFill($data)->save();
        $this->audit($provider, 'settings_updated', array_keys($data), $user);
    }

    public function setApiKey(string $provider, string $apiKey, ?User $user = null): void
    {
        $this->assertProvider($provider);
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw ValidationException::withMessages(['api_key' => 'Geef een niet-lege API-sleutel op.']);
        }

        AiProviderConfig::query()->firstOrCreate(['provider' => $provider])->forceFill([
            'api_key' => $apiKey,
            'updated_by_user_id' => $user?->id,
        ])->save();
        $this->audit($provider, 'api_key_set', ['api_key'], $user);
    }

    public function deleteApiKey(string $provider, ?User $user = null): void
    {
        $this->assertProvider($provider);
        AiProviderConfig::query()->where('provider', $provider)->update([
            'api_key' => null,
            'updated_by_user_id' => $user?->id,
        ]);
        $this->audit($provider, 'api_key_deleted', ['api_key'], $user);
    }

    public function monthlyBudget(string $provider): int
    {
        $row = $this->row($provider);

        return $row instanceof AiProviderConfig ? (int) $row->monthly_budget_cents : 0;
    }

    public function cost(string $provider, string $capability): int
    {
        $row = $this->row($provider);

        return $capability === 'embeddings'
            ? ($row instanceof AiProviderConfig ? (int) $row->cost_cents_per_embedding : 0)
            : ($row instanceof AiProviderConfig ? (int) $row->cost_cents_per_image : 0);
    }

    private function row(string $provider): ?AiProviderConfig
    {
        $this->assertProvider($provider);

        return AiProviderConfig::query()->where('provider', $provider)->first();
    }

    private function assertProvider(string $provider): void
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            Log::warning('Unknown AI provider configuration requested', ['provider' => $provider]);
            throw new \InvalidArgumentException("Onbekende AI-provider: {$provider}.");
        }
    }

    /** @param list<string> $changedFields */
    private function audit(string $provider, string $action, array $changedFields, ?User $user): void
    {
        DB::table('ai_provider_config_audits')->insert([
            'user_id' => $user?->id,
            'provider' => $provider,
            'action' => $action,
            'changed_fields' => json_encode(array_values(array_diff($changedFields, ['updated_by_user_id'])), JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function nullableText(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    private function nonNegativeInt(mixed $value): int
    {
        return max(0, (int) $value);
    }

    private function isPublicHttpsEndpoint(mixed $endpoint): bool
    {
        if (! is_string($endpoint) || $endpoint === '') {
            return false;
        }

        $parts = parse_url($endpoint);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return false;
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip === false) {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
