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
        'external_endpoint' => env('AI_EXTERNAL_ENDPOINT'),
        'provider_region' => env('AI_PROVIDER_REGION'),
        'retention_notice' => env('AI_RETENTION_NOTICE'),
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
    'external_api_key' => env('AI_EXTERNAL_API_KEY'),

    // Native provider adapters call these first-party APIs directly, with no
    // required intermediary gateway/container. base_url is intentionally
    // NOT admin-UI-editable (unlike the custom local/external endpoints,
    // which are operator-trusted by design) to prevent an admin form from
    // being used to redirect a "native" call at an arbitrary host (SSRF).
    'native_providers' => [
        'openai' => [
            'enabled' => env('AI_OPENAI_ENABLED', false),
            'api_key' => env('AI_OPENAI_API_KEY'),
            'base_url' => env('AI_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'vision_model' => env('AI_OPENAI_VISION_MODEL', 'gpt-4.1-mini'),
            'cost_cents_per_image' => (int) env('AI_OPENAI_COST_CENTS_PER_IMAGE', 0),
            'monthly_budget_cents' => (int) env('AI_OPENAI_MONTHLY_BUDGET_CENTS', 0),
        ],
        'anthropic' => [
            'enabled' => env('AI_ANTHROPIC_ENABLED', false),
            'api_key' => env('AI_ANTHROPIC_API_KEY'),
            'base_url' => env('AI_ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'api_version' => env('AI_ANTHROPIC_API_VERSION', '2023-06-01'),
            'vision_model' => env('AI_ANTHROPIC_VISION_MODEL', 'claude-sonnet-5'),
            'cost_cents_per_image' => (int) env('AI_ANTHROPIC_COST_CENTS_PER_IMAGE', 0),
            'monthly_budget_cents' => (int) env('AI_ANTHROPIC_MONTHLY_BUDGET_CENTS', 0),
        ],
        'gemini' => [
            'enabled' => env('AI_GEMINI_ENABLED', false),
            'api_key' => env('AI_GEMINI_API_KEY'),
            'base_url' => env('AI_GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
            'vision_model' => env('AI_GEMINI_VISION_MODEL', 'gemini-2.5-flash'),
            // gemini-embedding-2 is Google's documented multimodal, shared
            // text/image embedding space model. gemini-embedding-001 is
            // text-only and must never be used for image embeddings.
            'embedding_model' => env('AI_GEMINI_EMBEDDING_MODEL', 'gemini-embedding-2'),
            'cost_cents_per_image' => (int) env('AI_GEMINI_COST_CENTS_PER_IMAGE', 0),
            'cost_cents_per_embedding' => (int) env('AI_GEMINI_COST_CENTS_PER_EMBEDDING', 0),
            'monthly_budget_cents' => (int) env('AI_GEMINI_MONTHLY_BUDGET_CENTS', 0),
        ],
        'openrouter' => [
            'enabled' => env('AI_OPENROUTER_ENABLED', false),
            'api_key' => env('AI_OPENROUTER_API_KEY'),
            'base_url' => env('AI_OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'vision_model' => env('AI_OPENROUTER_VISION_MODEL'),
            'embedding_model' => env('AI_OPENROUTER_EMBEDDING_MODEL'),
            // OpenRouter's own embeddings docs support image input only on
            // specific upstream models; only allowlisted models may be
            // selected so a text-only model can never end up mixed into an
            // image-embedding vector space.
            'embedding_model_allowlist' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('AI_OPENROUTER_EMBEDDING_MODEL_ALLOWLIST', 'nvidia/llama-nemotron-embed-vl-1b-v2'))
            ))),
            'cost_cents_per_image' => (int) env('AI_OPENROUTER_COST_CENTS_PER_IMAGE', 0),
            'cost_cents_per_embedding' => (int) env('AI_OPENROUTER_COST_CENTS_PER_EMBEDDING', 0),
            'monthly_budget_cents' => (int) env('AI_OPENROUTER_MONTHLY_BUDGET_CENTS', 0),
        ],
    ],
];
