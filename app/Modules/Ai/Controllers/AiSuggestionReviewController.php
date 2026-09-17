<?php

declare(strict_types=1);

namespace App\Modules\Ai\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Ai\Models\AiSuggestion;
use App\Modules\Ai\Services\AiSuggestionReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AiSuggestionReviewController extends Controller
{
    public function __construct(
        private readonly AiSuggestionReviewService $review,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('catalogue.manage') || $user->hasPermission('assets.update')), 403);

        $suggestions = AiSuggestion::query()
            ->with(['asset', 'assetFile', 'run'])
            ->where('review_status', AiSuggestion::REVIEW_PENDING)
            ->when(! $user->hasPermission('assets.publish'), function ($query) use ($user): void {
                $query->whereHas('asset', fn ($assetQuery) => $assetQuery->where('created_by_user_id', $user->id));
            })
            ->latest('created_at')
            ->paginate(25);

        return view('ai.suggestions.index', compact('suggestions'));
    }

    public function accept(Request $request, AiSuggestion $suggestion): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $suggestion->load('asset');
        $this->authorize('update', $suggestion->asset);

        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
            'return_to' => ['nullable', Rule::in(['asset'])],
        ]);

        $this->review->accept($suggestion, $user, (int) $validated['lock_version']);

        return $this->reviewRedirect($suggestion, $validated['return_to'] ?? null)
            ->with('status', 'AI-suggestie geaccepteerd en als metadatawijziging opgeslagen.');
    }

    public function reject(Request $request, AiSuggestion $suggestion): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $suggestion->load('asset');
        $this->authorize('update', $suggestion->asset);

        $validated = $request->validate([
            'review_note' => ['nullable', 'string', 'max:500'],
            'return_to' => ['nullable', Rule::in(['asset'])],
        ]);

        $this->review->reject($suggestion, $user, $validated['review_note'] ?? null);

        return $this->reviewRedirect($suggestion, $validated['return_to'] ?? null)
            ->with('status', 'AI-suggestie afgewezen zonder metadata te wijzigen.');
    }

    private function reviewRedirect(AiSuggestion $suggestion, ?string $returnTo): RedirectResponse
    {
        return $returnTo === 'asset'
            ? redirect()->to(route('admin.assets.show', $suggestion->asset_id).'#ai-results')
            : redirect()->route('admin.operations.ai.suggestions.index');
    }
}
