<?php

declare(strict_types=1);

return [
    'defaults' => [
        'global_enabled' => false,
        'emergency_stop' => false,
        'image_analysis_enabled' => false,
        'embeddings_enabled' => false,
        'local_provider_enabled' => false,
        'external_provider_enabled' => false,
        'external_processing_allowed' => false,
        'openai_provider_enabled' => false,
        'anthropic_provider_enabled' => false,
        'gemini_provider_enabled' => false,
        'openrouter_provider_enabled' => false,
        'local_endpoint' => env('AI_LOCAL_ENDPOINT'),
        'external_endpoint' => null,
        'provider_region' => null,
        'retention_notice' => null,
        'max_assets_per_batch' => 25,
        'derivative_max_pixels' => 1024,
        'request_timeout_seconds' => 60,
        'monthly_external_budget_cents' => 0,
        // Per-capability provider/model choice. Kept separate from the
        // legacy `local`/`external` toggles above: an operator can enable a
        // native provider without it being selected for either capability,
        // and image-analysis vs. embeddings can point at different
        // providers/models entirely (e.g. OpenAI for analysis, Gemini for
        // embeddings) because not every provider supports both.
        'image_analysis_provider' => null,
        'image_analysis_model' => null,
        'image_analysis_native_consent' => false,
        'embeddings_provider' => null,
        'embeddings_model' => null,
        'embeddings_native_consent' => false,
    ],
    // Native provider adapters call these first-party APIs directly, with no
    // required intermediary gateway/container. base_url is intentionally
    // NOT admin-UI-editable (unlike the custom local/external endpoints,
    // which are operator-trusted by design) to prevent an admin form from
    // being used to redirect a "native" call at an arbitrary host (SSRF).
    // Provider secrets, models, costs and budgets are database-owned after
    // the AI provider migration. Only fixed official endpoints, versions and
    // the multimodal OpenRouter allowlist belong in configuration.
    'native_providers' => [
        'openai' => [
            'base_url' => 'https://api.openai.com/v1',
        ],
        'anthropic' => [
            'base_url' => 'https://api.anthropic.com',
            'api_version' => '2023-06-01',
        ],
        'gemini' => [
            'base_url' => 'https://generativelanguage.googleapis.com',
        ],
        'openrouter' => [
            'base_url' => 'https://openrouter.ai/api/v1',
            'embedding_model_allowlist' => ['nvidia/llama-nemotron-embed-vl-1b-v2'],
        ],
    ],
];
