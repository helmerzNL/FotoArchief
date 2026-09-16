<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Catalogue\Models\Tag;
use App\Modules\Catalogue\Models\TagSynonym;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TagController extends Controller
{
    public function index(Request $request): View
    {
        $query = Tag::query()->withCount(['assets', 'synonyms'])->with('synonyms');

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', '%'.$q.'%')
                    ->orWhere('description', 'like', '%'.$q.'%')
                    ->orWhereHas('synonyms', fn ($sq) => $sq->where('name', 'like', '%'.$q.'%'));
            });
        }

        $tags = $query->orderBy('name')->get();

        return view('catalogue.tags.index', [
            'tags' => $tags,
        ]);
    }

    public function create(): View
    {
        return view('catalogue.tags.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:tags,name', 'unique:tag_synonyms,name'],
            'description' => ['nullable', 'string', 'max:2000'],
            'synonyms' => ['nullable', 'string', 'max:500'],
        ]);

        $tag = Tag::query()->create([
            'name' => trim($validated['name']),
            'slug' => Str::slug(trim($validated['name'])),
            'description' => $validated['description'] ?? null,
        ]);

        $this->syncSynonyms($tag, $validated['synonyms'] ?? '');

        return redirect()->route('catalogue.tags.show', $tag)->with('status', 'Tag aangemaakt.');
    }

    public function show(Request $request, Tag $tag): View
    {
        $user = $this->user($request);
        $tag->load(['synonyms']);

        $assetsQuery = $tag->assets()->with(['files', 'uploads']);
        if ($user->cannot('assets.publish')) {
            $assetsQuery->where('assets.created_by_user_id', $user->id);
        }
        $assets = $assetsQuery->latest('assets.id')->limit(50)->get();

        $otherTags = Tag::query()->where('id', '!=', $tag->id)->orderBy('name')->get();

        return view('catalogue.tags.show', [
            'tag' => $tag,
            'assets' => $assets,
            'otherTags' => $otherTags,
        ]);
    }

    public function edit(Tag $tag): View
    {
        $tag->load('synonyms');
        $otherTags = Tag::query()->where('id', '!=', $tag->id)->orderBy('name')->get();

        return view('catalogue.tags.edit', [
            'tag' => $tag,
            'otherTags' => $otherTags,
        ]);
    }

    public function update(Request $request, Tag $tag): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('tags', 'name')->ignore($tag->id), Rule::unique('tag_synonyms', 'name')],
            'description' => ['nullable', 'string', 'max:2000'],
            'synonyms' => ['nullable', 'string', 'max:500'],
        ]);

        $tag->update([
            'name' => trim($validated['name']),
            'slug' => Str::slug(trim($validated['name'])),
            'description' => $validated['description'] ?? null,
        ]);

        $this->syncSynonyms($tag, $validated['synonyms'] ?? '');

        return redirect()->route('catalogue.tags.show', $tag)->with('status', 'Tag bijgewerkt.');
    }

    public function merge(Request $request, Tag $tag): RedirectResponse
    {
        $validated = $request->validate([
            'target_tag_id' => ['required', 'string', 'exists:tags,id', Rule::notIn([$tag->id])],
        ]);

        /** @var Tag $targetTag */
        $targetTag = Tag::query()->findOrFail($validated['target_tag_id']);

        DB::transaction(function () use ($tag, $targetTag) {
            // 1. Move all asset associations to target tag
            $assetIds = $tag->assets()->pluck('assets.id');
            foreach ($assetIds as $assetId) {
                if (! $targetTag->assets()->where('assets.id', $assetId)->exists()) {
                    $targetTag->assets()->attach($assetId, ['id' => (string) Str::ulid()]);
                }
            }
            $tag->assets()->detach();

            // 2. Move existing synonyms from source to target
            foreach ($tag->synonyms as $synonym) {
                $synonym->update(['tag_id' => $targetTag->id]);
            }

            // 3. Make the old source tag name a synonym of target tag if not duplicate
            if (! TagSynonym::query()->where('name', $tag->name)->exists() && $targetTag->name !== $tag->name) {
                TagSynonym::query()->create([
                    'tag_id' => $targetTag->id,
                    'name' => $tag->name,
                    'slug' => Str::slug($tag->name),
                ]);
            }

            // 4. Delete the source tag
            $tag->delete();
        });

        return redirect()->route('catalogue.tags.show', $targetTag)->with('status', sprintf('Tag "%s" succesvol samengevoegd met "%s".', $tag->name, $targetTag->name));
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        DB::transaction(function () use ($tag) {
            $tag->assets()->detach();
            $tag->delete();
        });

        return redirect()->route('catalogue.tags.index')->with('status', 'Tag verwijderd.');
    }

    private function syncSynonyms(Tag $tag, string $synonymsInput): void
    {
        $names = collect(explode(',', $synonymsInput))
            ->map(fn (string $s) => trim($s))
            ->filter(fn (string $s) => $s !== '' && $s !== $tag->name)
            ->unique();

        // Check uniqueness across other tags
        foreach ($names as $name) {
            $slug = Str::slug($name);
            $conflictTag = Tag::query()->where('id', '!=', $tag->id)->where(function ($q) use ($name, $slug) {
                $q->where('name', $name)->orWhere('slug', $slug);
            })->first();

            if ($conflictTag) {
                throw ValidationException::withMessages([
                    'synonyms' => sprintf('Synoniem "%s" is al in gebruik als tagnaam voor "%s".', $name, $conflictTag->name),
                ]);
            }
        }

        $tag->synonyms()->whereNotIn('name', $names)->delete();

        foreach ($names as $name) {
            $tag->synonyms()->firstOrCreate(
                ['name' => $name],
                ['slug' => Str::slug($name)]
            );
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
