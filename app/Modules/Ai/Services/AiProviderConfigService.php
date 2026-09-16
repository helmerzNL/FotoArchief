<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Models\AiProviderConfig;
use App\Modules\Ai\Services\Native\AbstractNativeProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AiProviderConfigService
{
    public const PROVIDERS = ['openai', 'anthropic', 'gemini', 'openrouter'];

    /**
     * @return array<string, mixed>
     */
    public function status(string $provider): array
    {
        $row = $this->row($provider);

        return [
            'provider' => $provider,
            'enabled' => (bool) ($row?->enabled ?? false),
            'has_api_key' => is_string($row?->api_key) && $row->api_key !== '',
            'vision_model' => $row?->vision_model,
            'embedding_model' => $row?->embedding_model,
            'cost_cents_per_image' => (int) ($row?->cost_cents_per_image ?? 0),
            'cost_cents_per_embedding' => (int) ($row?->cost_cents_per_embedding ?? 0),
            'monthly_budget_cents' => (int) ($row?->monthly_budget_cents ?? 0),
            'base_url' => AbstractNativeProvider::officialBaseUrl($provider),
            'api_version' => AbstractNativeProvider::officialApiVersion($provider),
            'embedding_model_allowlist' => AbstractNativeProvider::embeddingModelAllowlist($provider),
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

        return [
            'enabled' => $row->enabled,
            'api_key' => $row->api_key,
            'vision_model' => $row->vision_model,
            'embedding_model' => $row->embedding_model,
            'cost_cents_per_image' => $row->cost_cents_per_image,
            'cost_cents_per_embedding' => $row->cost_cents_per_embedding,
            'monthly_budget_cents' => $row->monthly_budget_cents,
            'base_url' => AbstractNativeProvider::officialBaseUrl($provider),
            'api_version' => AbstractNativeProvider::officialApiVersion($provider),
            'embedding_model_allowlist' => AbstractNativeProvider::embeddingModelAllowlist($provider),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(string $provider, array $input): void
    {
        $this->assertProvider($provider);
        $data = [
            'enabled' => (bool) ($input['enabled'] ?? false),
            'vision_model' => $this->nullableText($input['vision_model'] ?? null),
            'embedding_model' => $this->nullableText($input['embedding_model'] ?? null),
            'cost_cents_per_image' => $this->nonNegativeInt($input['cost_cents_per_image'] ?? 0),
            'cost_cents_per_embedding' => $this->nonNegativeInt($input['cost_cents_per_embedding'] ?? 0),
            'monthly_budget_cents' => $this->nonNegativeInt($input['monthly_budget_cents'] ?? 0),
        ];
        $allowlist = AbstractNativeProvider::embeddingModelAllowlist($provider);
        if ($provider === 'openrouter' && $data['embedding_model'] !== null && ! in_array($data['embedding_model'], $allowlist, true)) {
            throw ValidationException::withMessages(['embedding_model' => 'OpenRouter-embeddings moeten op de vaste multimodale allowlist staan.']);
        }

        $row = AiProviderConfig::query()->firstOrCreate(['provider' => $provider]);
        $row->forceFill($data)->save();
    }

    public function setApiKey(string $provider, string $apiKey): void
    {
        $this->assertProvider($provider);
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw ValidationException::withMessages(['api_key' => 'Geef een niet-lege API-sleutel op.']);
        }

        AiProviderConfig::query()->firstOrCreate(['provider' => $provider])->forceFill([
            'api_key' => $apiKey,
        ])->save();
    }

    public function deleteApiKey(string $provider): void
    {
        $this->assertProvider($provider);
        AiProviderConfig::query()->where('provider', $provider)->update(['api_key' => null]);
    }

    public function monthlyBudget(string $provider): int
    {
        return (int) ($this->row($provider)?->monthly_budget_cents ?? 0);
    }

    public function cost(string $provider, string $capability): int
    {
        $row = $this->row($provider);

        return $capability === 'embeddings'
            ? (int) ($row?->cost_cents_per_embedding ?? 0)
            : (int) ($row?->cost_cents_per_image ?? 0);
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
            throw new \InvalidArgumentException("Onbekende native AI-provider: {$provider}.");
        }
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
}
