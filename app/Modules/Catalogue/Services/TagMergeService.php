<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Services;

use App\Modules\Catalogue\Models\Tag;
use App\Modules\Catalogue\Models\TagSynonym;
use App\Modules\Catalogue\Support\TagSlug;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TagMergeService
{
    /**
     * Moves everything from $source to $target and deletes $source. The source's
     * name stays findable as a synonym of the target.
     */
    public function merge(Tag $source, Tag $target): void
    {
        DB::transaction(function () use ($source, $target): void {
            $linked = $target->assets()->pluck('assets.id')->all();
            foreach ($source->assets()->pluck('assets.id') as $assetId) {
                if (! in_array($assetId, $linked, true)) {
                    $target->assets()->attach($assetId, ['id' => (string) Str::ulid()]);
                }
            }
            $source->assets()->detach();

            foreach ($source->synonyms as $synonym) {
                $synonym->update(['tag_id' => $target->id]);
            }

            $slug = TagSlug::for($source->name);
            $taken = TagSynonym::query()->where('name', $source->name)->orWhere('slug', $slug)->exists();
            if (! $taken && $target->name !== $source->name) {
                TagSynonym::query()->create(['tag_id' => $target->id, 'name' => $source->name, 'slug' => $slug]);
            }

            $source->delete();
        });
    }
}
