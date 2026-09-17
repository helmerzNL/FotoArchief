<?php

declare(strict_types=1);

namespace App\Modules\Ai\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Ai\Models\AiSuggestion;
use App\Modules\Ai\Services\AiSuggestionReviewService;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['reviewable', 'pending', 'accepted', 'rejected', 'superseded', 'reverted'])],
            'collection' => ['nullable', 'string', 'max:26'],
            'provider' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);
        $suggestions = AiSuggestion::query()
            ->with(['asset.tags', 'assetFile', 'run'])
            ->whereHas('asset')
            ->when(($filters['status'] ?? 'reviewable') !== 'reviewable',
                fn ($query) => $query->where('review_status', $filters['status']),
                fn ($query) => $query->where(function (Builder $query): void {
                    $query->where('review_status', AiSuggestion::REVIEW_PENDING)
                        ->orWhere(function (Builder $query): void {
                            $query->where('review_status', AiSuggestion::REVIEW_SUPERSEDED)
                                ->whereHas('assetFile', fn ($file) => $file->where('is_primary', true)
                                    ->whereColumn('asset_files.asset_id', 'ai_suggestions.asset_id')
                                    ->whereColumn('asset_files.sha256', 'ai_suggestions.source_file_sha256'));
                        });
                }))
            ->when(! $user->hasPermission('assets.publish'), function ($query) use ($user): void {
                $query->whereHas('asset', fn ($assetQuery) => $assetQuery->where('created_by_user_id', $user->id));
            })
            ->when($filters['collection'] ?? null, fn ($query, $id) => $query->whereHas('asset.collections', fn ($collection) => $collection->whereKey($id)))
            ->when($filters['provider'] ?? null, fn ($query, $provider) => $query->whereHas('run', fn ($run) => $run->where('provider_name', $provider)))
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['until'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->latest('created_at')
            ->paginate(25)->withQueryString();
        $collections = Collection::query()->whereHas('assets', function ($query) use ($user): void {
            if (! $user->hasPermission('assets.publish')) {
                $query->where('created_by_user_id', $user->id);
            }
        })->orderBy('title')->get(['id', 'title']);

        return view('ai.suggestions.index', compact('suggestions', 'filters', 'collections'));
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
            'edited_description' => ['sometimes', 'required', 'string', 'max:10000'],
        ]);

        $this->review->accept($suggestion, $user, (int) $validated['lock_version'], $validated['edited_description'] ?? null);

        return $this->reviewRedirect($suggestion, $validated['return_to'] ?? null)
            ->with('status', __('ai.suggestions.accepted'));
    }

    public function undo(Request $request, AiSuggestion $suggestion): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        $this->review->undo($suggestion, $user, (int) $validated['lock_version']);

        return back()->with('status', __('review.undone'));
    }

    public function bulk(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $validated = $request->validate([
            'confirm' => ['accepted'],
            'decision' => ['required', Rule::in(['accept', 'reject'])],
            'selected' => ['required', 'array', 'min:1', 'max:25'],
            'selected.*' => ['required', 'string', 'distinct', 'size:26'],
            'versions' => ['required', 'array', 'max:25'],
            'versions.*' => ['required', 'integer', 'min:1'],
        ]);
        $suggestions = AiSuggestion::query()->with('asset')->whereIn('id', $validated['selected'])->get();
        abort_unless($suggestions->count() === count($validated['selected']), 404);
        foreach ($suggestions as $suggestion) {
            abort_if($suggestion->asset === null, 404);
            $this->authorize('update', $suggestion->asset);
        }
        $results = [];
        foreach ($suggestions->groupBy('asset_id') as $assetId => $items) {
            DB::transaction(function () use ($assetId, $items, $validated, $user, &$results): void {
                $asset = Asset::query()->lockForUpdate()->findOrFail($assetId);
                $initialVersion = $asset->lock_version;
                foreach ($items as $suggestion) {
                    try {
                        if ((int) ($validated['versions'][$suggestion->id] ?? 0) !== $initialVersion) {
                            throw ValidationException::withMessages(['lock_version' => __('ai.errors.photo_changed')]);
                        }
                        if ($validated['decision'] === 'accept') {
                            $this->review->accept($suggestion, $user, $asset->refresh()->lock_version);
                        } else {
                            $this->review->reject($suggestion, $user);
                        }
                        $results[] = ['id' => $suggestion->id, 'message' => __('review.processed')];
                    } catch (ValidationException $exception) {
                        $results[] = ['id' => $suggestion->id, 'message' => implode(' ', $exception->validator->errors()->all())];
                    }
                }
            });
        }

        return back()->with('review_results', $results);
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
            ->with('status', __('ai.suggestions.rejected'));
    }

    private function reviewRedirect(AiSuggestion $suggestion, ?string $returnTo): RedirectResponse
    {
        return $returnTo === 'asset'
            ? redirect()->to(route('admin.assets.show', $suggestion->asset_id).'#ai-results')
            : redirect()->route('admin.operations.ai.suggestions.index');
    }
}
