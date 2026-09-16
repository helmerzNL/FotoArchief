<?php

declare(strict_types=1);

namespace App\Modules\Ai\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Ai\Services\AiConfigurationService;
use App\Modules\Ai\Services\AiDispatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiSettingsController extends Controller
{
    public function __construct(
        private readonly AiConfigurationService $configuration,
        private readonly AiDispatchService $dispatch,
    ) {}

    public function edit(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('users.manage'), 403);

        return view('ai.settings', [
            'settings' => $this->configuration->effective(),
        ]);
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
            'external_provider_enabled' => ['nullable', 'boolean'],
            'external_processing_allowed' => ['nullable', 'boolean'],
            'local_endpoint' => ['nullable', 'string', 'max:255'],
            'external_endpoint' => ['nullable', 'string', 'max:255'],
            'provider_region' => ['nullable', 'string', 'max:120'],
            'retention_notice' => ['nullable', 'string', 'max:500'],
            'max_assets_per_batch' => ['required', 'integer', 'min:1', 'max:25'],
            'derivative_max_pixels' => ['required', 'integer', 'min:256', 'max:1024'],
            'request_timeout_seconds' => ['required', 'integer', 'min:5', 'max:60'],
            'monthly_external_budget_cents' => ['required', 'integer', 'min:0', 'max:10000000'],
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
            'asset_ids' => ['required', 'array', 'min:1', 'max:25'],
            'asset_ids.*' => ['required', 'string'],
            'provider' => ['required', 'string', 'in:local,external'],
        ]);

        $run = $this->dispatch->dispatchImageAnalysis($validated['asset_ids'], $validated['provider'], $user);

        return redirect()
            ->route('admin.operations.runs.index')
            ->with('status', "AI-analyse {$run->id} is in de achtergrondwachtrij geplaatst.");
    }
}
