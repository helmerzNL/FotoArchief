<?php

declare(strict_types=1);

namespace App\Modules\Ai\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Ai\Services\AiConfigurationService;
use App\Modules\Ai\Services\AiConnectionTestService;
use App\Modules\Ai\Services\AiDispatchService;
use App\Modules\Ai\Services\AiProviderConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AiSettingsController extends Controller
{
    public function __construct(
        private readonly AiConfigurationService $configuration,
        private readonly AiDispatchService $dispatch,
        private readonly AiConnectionTestService $connectionTest,
        private readonly AiProviderConfigService $providerConfigs,
    ) {}

    public function edit(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);

        return view('ai.settings', [
            'settings' => $this->configuration->effective(),
            'providerStatuses' => array_map(fn (string $provider): array => $this->providerConfigs->status($provider), AiProviderConfigService::PROVIDERS),
        ]);
    }

    public function updateProvider(Request $request, string $provider): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);
        abort_unless(in_array($provider, AiProviderConfigService::PROVIDERS, true), 404);

        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'vision_model' => ['nullable', 'string', 'max:160'],
            'embedding_model' => ['nullable', 'string', 'max:160'],
            'endpoint' => ['nullable', 'string', 'max:255'],
            'provider_region' => ['nullable', 'string', 'max:120'],
            'retention_notice' => ['nullable', 'string', 'max:500'],
            'cost_cents_per_image' => ['required', 'integer', 'min:0', 'max:1000000'],
            'cost_cents_per_embedding' => ['required', 'integer', 'min:0', 'max:1000000'],
            'monthly_budget_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
        ]);
        $this->providerConfigs->update($provider, $validated, $user);

        return redirect()->route('admin.operations.ai.edit')->with('status', 'AI-providerinstellingen opgeslagen; vaste endpoints en versies zijn niet wijzigbaar.');
    }

    public function setProviderKey(Request $request, string $provider): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);
        abort_unless(in_array($provider, AiProviderConfigService::PROVIDERS, true), 404);
        $request->validate(['api_key' => ['required', 'string', 'max:1000']]);
        $this->providerConfigs->setApiKey($provider, (string) $request->string('api_key'), $user);

        return redirect()->route('admin.operations.ai.edit')->with('status', 'API-sleutel opgeslagen. De sleutel wordt niet getoond of teruggegeven.');
    }

    public function deleteProviderKey(Request $request, string $provider): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);
        abort_unless(in_array($provider, AiProviderConfigService::PROVIDERS, true), 404);
        $request->validate(['confirm_delete' => ['accepted']]);
        $this->providerConfigs->deleteApiKey($provider, $user);

        return redirect()->route('admin.operations.ai.edit')->with('status', 'API-sleutel verwijderd.');
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);

        $validated = $request->validate([
            'global_enabled' => ['nullable', 'boolean'],
            'emergency_stop' => ['nullable', 'boolean'],
            'image_analysis_enabled' => ['nullable', 'boolean'],
            'embeddings_enabled' => ['nullable', 'boolean'],
            'local_provider_enabled' => ['nullable', 'boolean'],
            'external_processing_allowed' => ['nullable', 'boolean'],
            'local_endpoint' => ['nullable', 'string', 'max:255'],
            'max_assets_per_batch' => ['required', 'integer', 'min:1', 'max:25'],
            'derivative_max_pixels' => ['required', 'integer', 'min:256', 'max:1024'],
            'request_timeout_seconds' => ['required', 'integer', 'min:5', 'max:60'],
            'image_analysis_provider' => ['nullable', 'string', Rule::in(AiConfigurationService::IMAGE_ANALYSIS_PROVIDERS)],
            'image_analysis_model' => ['nullable', 'string', 'max:120'],
            'image_analysis_native_consent' => ['nullable', 'boolean'],
            'embeddings_provider' => ['nullable', 'string', Rule::in(AiConfigurationService::EMBEDDINGS_PROVIDERS)],
            'embeddings_model' => ['nullable', 'string', 'max:120'],
            'embeddings_native_consent' => ['nullable', 'boolean'],
        ]);

        $this->configuration->update($validated, $user);

        return redirect()
            ->route('admin.operations.ai.edit')
            ->with('status', 'AI-instellingen opgeslagen. Wijzigingen activeren nooit automatisch externe fallback.');
    }

    public function dispatchAnalysis(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);

        $validated = $request->validate([
            'asset_ids' => ['required', 'string'],
            'provider' => ['required', 'string', Rule::in(AiConfigurationService::IMAGE_ANALYSIS_PROVIDERS)],
        ]);
        $assetIds = array_values(array_filter(
            preg_split('/[\s,]+/', (string) $validated['asset_ids']) ?: [],
            fn (string $assetId): bool => $assetId !== '',
        ));

        $run = $this->dispatch->dispatchImageAnalysis($assetIds, $validated['provider'], $user);

        return redirect()
            ->route('admin.operations.runs.index')
            ->with('status', "AI-analyse {$run->id} is in de achtergrondwachtrij geplaatst.");
    }

    public function dispatchIndex(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('assets.update'), 403);

        $validated = $request->validate([
            'asset_ids' => ['required', 'string'],
            'provider' => ['required', 'string', Rule::in(AiConfigurationService::EMBEDDINGS_PROVIDERS)],
        ]);
        $assetIds = array_values(array_filter(
            preg_split('/[\s,]+/', (string) $validated['asset_ids']) ?: [],
            fn (string $assetId): bool => $assetId !== '',
        ));

        $run = $this->dispatch->dispatchEmbeddingIndex($assetIds, (string) $validated['provider'], $user);

        return redirect()
            ->route('admin.operations.runs.index')
            ->with('status', "AI-index {$run->id} is in de achtergrondwachtrij geplaatst.");
    }

    public function testConnection(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);

        $validated = $request->validate([
            'test_provider' => ['required', 'string', Rule::in(AiConfigurationService::IMAGE_ANALYSIS_PROVIDERS)],
            'test_capability' => ['required', 'string', Rule::in(['image_analysis', 'embeddings'])],
        ]);

        $result = $this->connectionTest->test((string) $validated['test_provider'], (string) $validated['test_capability']);

        return redirect()
            ->route('admin.operations.ai.edit')
            ->with('connection_test', $result + [
                'provider' => $validated['test_provider'],
                'capability' => $validated['test_capability'],
            ]);
    }
}
