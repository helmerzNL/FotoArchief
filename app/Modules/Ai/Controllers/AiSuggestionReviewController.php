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
use Illuminate\Support\Collection as SupportCollection;
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

        $filters = $this->validatedFilters($request);
        $suggestions = $this->suggestionQuery($user, $filters)
            ->when(($filters['sort'] ?? 'newest') === 'confidence', fn ($query) => $query->orderByDesc(DB::raw('coalesce(confidence,-1)')))
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
        $results = $this->decide($suggestions, $validated['decision'], $validated['versions'], $user);

        return back()->with('review_results', $results);
    }

    /**
     * Step one of "accept the confident tags": shows exactly which tags would be accepted
     * and nothing is changed. The photos' revisions are carried along so step two refuses
     * a photo that changed in the meantime.
     */
    public function confirmTags(Request $request): View
    {
        $user = $this->reviewer($request);
        $filters = $this->validatedFilters($request);
        $minimum = $request->validate(['min_confidence' => ['required', 'numeric', 'between:0,1']])['min_confidence'];
        $max = (int) config('ai.tag_suggestions.bulk_accept_max', 200);

        $eligible = $this->suggestionQuery($user, $filters)
            ->where('suggestion_type', AiSuggestion::TYPE_TAG)
            ->where('confidence', '>=', (float) $minimum)
            ->orderByDesc('confidence')
            ->limit($max + 1)
            ->get()
            ->filter(fn (AiSuggestion $suggestion): bool => $suggestion->canReview())
            ->values();
        $selected = $eligible->take($max);

        return view('ai.suggestions.confirm-tags', [
            'selected' => $selected,
            'moreThanMax' => $eligible->count() > $max,
            'max' => $max,
            'minimum' => (float) $minimum,
            'filters' => $filters,
            'tagCounts' => $selected->countBy('value')->sortDesc(),
            'photoCount' => $selected->pluck('asset_id')->unique()->count(),
        ]);
    }

    /** Step two: a person has seen the list and confirmed it; accept exactly those tags. */
    public function acceptTags(Request $request): RedirectResponse
    {
        $user = $this->reviewer($request);
        $max = (int) config('ai.tag_suggestions.bulk_accept_max', 200);
        $validated = $request->validate([
            'confirm' => ['accepted'],
            'min_confidence' => ['required', 'numeric', 'between:0,1'],
            'selected' => ['required', 'array', 'min:1', 'max:'.$max],
            'selected.*' => ['required', 'string', 'distinct', 'size:26'],
            'versions' => ['required', 'array', 'max:'.$max],
            'versions.*' => ['required', 'integer', 'min:1'],
        ]);
        $suggestions = AiSuggestion::query()->with('asset')->whereIn('id', $validated['selected'])->get();
        abort_unless($suggestions->count() === count($validated['selected']), 404);
        foreach ($suggestions as $suggestion) {
            abort_if($suggestion->asset === null, 404);
            $this->authorize('update', $suggestion->asset);
            // The page only offers confident tags; refuse anything else even if the form was edited.
            if ($suggestion->suggestion_type !== AiSuggestion::TYPE_TAG
                || $suggestion->confidence === null
                || (float) $suggestion->confidence < (float) $validated['min_confidence']) {
                throw ValidationException::withMessages(['selected' => __('ai.bulk_tags.not_eligible')]);
            }
        }

        $results = $this->decide($suggestions, 'accept', $validated['versions'], $user);
        $accepted = count(array_filter($results, fn (array $result): bool => $result['message'] === __('review.processed')));
        DB::table('bulk_operations')->insert([
            'id' => (string) str()->ulid(),
            'user_id' => $user->id,
            'operation_type' => 'ai_tag_bulk_accept',
            'affected_count' => $accepted,
            'payload' => json_encode(['min_confidence' => (float) $validated['min_confidence'], 'suggestion_ids' => array_values($validated['selected'])], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('admin.operations.ai.suggestions.index')
            ->with('status', __('ai.bulk_tags.done', ['accepted' => $accepted, 'total' => count($results)]))
            ->with('review_results', $results);
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

    private function reviewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('catalogue.manage') || $user->hasPermission('assets.update')), 403);

        return $user;
    }

    /** @return array<string, mixed> */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', Rule::in(['reviewable', 'pending', 'accepted', 'rejected', 'superseded', 'reverted'])],
            'collection' => ['nullable', 'string', 'max:26'],
            'provider' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'type' => ['nullable', Rule::in([AiSuggestion::TYPE_TAG, AiSuggestion::TYPE_DESCRIPTION])],
            'min_confidence_filter' => ['nullable', 'numeric', 'between:0,1'],
            'sort' => ['nullable', Rule::in(['newest', 'confidence'])],
        ]);
    }

    /**
     * The suggestions this person may review under the given filters.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<AiSuggestion>
     */
    private function suggestionQuery(User $user, array $filters): Builder
    {
        return AiSuggestion::query()
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
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('suggestion_type', $type))
            ->when(isset($filters['min_confidence_filter']), fn ($query) => $query->where('confidence', '>=', (float) $filters['min_confidence_filter']));
    }

    /**
     * Accepts or rejects the given suggestions, one transaction per photo, each against the
     * revision the reviewer saw. A stale or invalid item gets its own message and never
     * blocks the rest.
     *
     * @param  SupportCollection<int, AiSuggestion>  $suggestions
     * @param  array<string, mixed>  $versions
     * @return list<array{id: string, message: string}>
     */
    private function decide(SupportCollection $suggestions, string $decision, array $versions, User $user): array
    {
        $results = [];
        foreach ($suggestions->groupBy('asset_id') as $assetId => $items) {
            DB::transaction(function () use ($assetId, $items, $versions, $decision, $user, &$results): void {
                $asset = Asset::query()->lockForUpdate()->findOrFail($assetId);
                $initialVersion = $asset->lock_version;
                foreach ($items as $suggestion) {
                    try {
                        if ((int) ($versions[$suggestion->id] ?? 0) !== $initialVersion) {
                            throw ValidationException::withMessages(['lock_version' => __('ai.errors.photo_changed')]);
                        }
                        if ($decision === 'accept') {
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

        return $results;
    }

    private function reviewRedirect(AiSuggestion $suggestion, ?string $returnTo): RedirectResponse
    {
        return $returnTo === 'asset'
            ? redirect()->to(route('admin.assets.show', $suggestion->asset_id).'#ai-results')
            : redirect()->route('admin.operations.ai.suggestions.index');
    }
}
