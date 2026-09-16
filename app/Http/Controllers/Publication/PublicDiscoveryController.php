<?php

declare(strict_types=1);

namespace App\Http\Controllers\Publication;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Services\AiSemanticSearchService;
use App\Modules\Catalogue\Models\Collection;
use App\Modules\Catalogue\Models\Tag;
use App\Modules\Publication\Models\Publication;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Public search and collections. Every query starts from
 * Publication::publiclyVisible() (the same predicate the viewer, sitemap and
 * IIIF routes use) and is paginated with a keyset cursor on
 * (published_at, id) instead of OFFSET, so listings stay stable and fast as
 * the archive grows.
 */
class PublicDiscoveryController extends Controller
{
    private const PER_PAGE = 24;

    public function __construct(
        private readonly AiSemanticSearchService $semanticSearch,
    ) {}

    public function search(Request $request): View
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'semantic_q' => ['nullable', 'string', 'max:200'],
            'semantic_provider' => ['nullable', 'string', 'in:local,external'],
            'tag' => ['nullable', 'string', 'max:100'],
            'collection' => ['nullable', 'string', 'max:150'],
            'cursor' => ['nullable', 'ulid'],
        ]);
        $semanticError = null;
        $semanticQuery = $request->string('semantic_q')->trim()->value();
        if ($semanticQuery !== '' && ! $request->filled('cursor')) {
            try {
                $publications = $this->semanticSearch->searchPublic($semanticQuery, $request->string('semantic_provider')->value() ?: 'local', self::PER_PAGE);
                $nextCursor = null;
            } catch (ValidationException $exception) {
                $semanticError = collect($exception->errors())->flatten()->first();
                [$publications, $nextCursor] = $this->paginate($this->eligibleQuery($request), $request);
            }
        } else {
            [$publications, $nextCursor] = $this->paginate($this->eligibleQuery($request), $request);
        }
        $tags = Tag::query()->whereHas('assets.publication', fn (Builder $q) => $q->publiclyVisible())->orderBy('name')->limit(40)->get();

        return view('public.discover', ['publications' => $publications, 'nextCursor' => $nextCursor, 'tags' => $tags, 'semanticError' => $semanticError]);
    }

    public function collections(): View
    {
        $collections = Collection::query()
            ->where('collection_type', 'collection')
            ->whereHas('assets.publication', fn (Builder $q) => $q->publiclyVisible())
            ->orderBy('title')
            ->limit(50)
            ->get();

        return view('public.collections.index', compact('collections'));
    }

    public function collectionShow(Request $request, Collection $collection): View
    {
        $request->validate(['cursor' => ['nullable', 'ulid']]);
        $query = Publication::query()->publiclyVisible()->with(['asset.files'])
            ->whereHas('asset.collections', fn (Builder $q) => $q->where('collections.id', $collection->id));
        [$publications, $nextCursor] = $this->paginate($query, $request);

        return view('public.collections.show', compact('collection', 'publications', 'nextCursor'));
    }

    /**
     * @return Builder<Publication>
     */
    private function eligibleQuery(Request $request): Builder
    {
        $query = Publication::query()->publiclyVisible()->with(['asset.files']);
        if ($q = $request->string('q')->trim()->value()) {
            $query->whereHas('asset', fn (Builder $a) => $a->where(fn (Builder $w) => $w->whereLike('title', '%'.$q.'%')->orWhereLike('description', '%'.$q.'%')));
        }
        if ($tag = $request->string('tag')->trim()->value()) {
            $query->whereHas('asset.tags', fn (Builder $t) => $t->where('slug', $tag));
        }
        if ($collectionSlug = $request->string('collection')->trim()->value()) {
            $query->whereHas('asset.collections', fn (Builder $c) => $c->where('slug', $collectionSlug));
        }

        return $query;
    }

    /**
     * @param  Builder<Publication>  $query
     * @return array{0: SupportCollection<int, Publication>, 1: ?string}
     */
    private function paginate(Builder $query, Request $request): array
    {
        $query->orderByDesc('publications.published_at')->orderByDesc('publications.id');
        if ($cursorId = $request->string('cursor')->value()) {
            $cursor = Publication::query()->publiclyVisible()->find($cursorId);
            if ($cursor !== null) {
                $query->where(function (Builder $q) use ($cursor): void {
                    $q->where('publications.published_at', '<', $cursor->published_at)
                        ->orWhere(function (Builder $q2) use ($cursor): void {
                            $q2->where('publications.published_at', $cursor->published_at)->where('publications.id', '<', $cursor->id);
                        });
                });
            }
        }
        $publications = $query->limit(self::PER_PAGE + 1)->get();
        $hasMore = $publications->count() > self::PER_PAGE;
        $publications = $publications->take(self::PER_PAGE);

        return [$publications, $hasMore ? $publications->last()?->id : null];
    }
}
