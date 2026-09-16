<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Exceptions\AiProviderException;

/**
 * Cheap, non-billable "connection test": lists the models the configured
 * API key can see and reports whether the operator's configured model is
 * among them. Never calls analyzeImage()/embedText()/embedImage() — those
 * cost real provider tokens and only ever run from the actual dispatch
 * pipeline (which itself is gated by persistent consent + budget
 * reservation). There is deliberately no separate "paid image proof" mode
 * here: the existing analyse-dispatch action already performs one real,
 * budget-reserved analyzeImage() call per asset and is the only sanctioned
 * way to prove a provider end-to-end, so this service never duplicates
 * that call path under a different name.
 */
class AiConnectionTestService
{
    public function __construct(
        private readonly AiConfigurationService $configuration,
        private readonly AiProviderResolver $resolver,
    ) {}

    /**
     * @return array{status: string, message: string, models_count?: int, model_configured?: string, model_found?: bool}
     */
    public function test(string $provider, string $capability): array
    {
        $allowedProviders = match ($capability) {
            'image_analysis' => AiConfigurationService::IMAGE_ANALYSIS_PROVIDERS,
            'embeddings' => AiConfigurationService::EMBEDDINGS_PROVIDERS,
            default => [],
        };

        if ($allowedProviders === [] || ! in_array($provider, $allowedProviders, true)) {
            return ['status' => 'error', 'message' => "Provider {$provider} ondersteunt capability {$capability} niet."];
        }

        $settings = $this->configuration->effective();
        $configuredModel = (string) ($settings["{$capability}_model"] ?? '');

        try {
            $probe = $this->resolver->resolveConnectionProbe($provider);
            $result = $probe->probe();
        } catch (AiProviderException $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }

        $models = $result['models'] ?? null;

        if (! is_array($models)) {
            // Local/external providers report capabilities (provider_kind,
            // supported capability flags, dimensions), not a models list —
            // reaching this point already proves the capability contract
            // held (assertCapabilities() would have thrown otherwise).
            return [
                'status' => 'ok',
                'message' => 'Verbinding gelukt. Capability-contract geverifieerd (geen betaalde beeldanalyse uitgevoerd).',
            ];
        }

        return [
            'status' => 'ok',
            'message' => 'Verbinding gelukt. Dit is een kosteloze capability-check, geen betaalde beeldanalyse.',
            'models_count' => count($models),
            'model_configured' => $configuredModel,
            'model_found' => $configuredModel !== '' && in_array($configuredModel, $models, true),
        ];
    }
}
