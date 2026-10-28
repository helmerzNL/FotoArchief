<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Tags are matched on their slug, but bulk edits used to store a sha256 of the name
 * as slug while every other writer stored a readable slug. The same word could
 * therefore exist twice. This gives every tag the canonical slug (the same rule as
 * App\Modules\Catalogue\Support\TagSlug, copied here so the migration keeps working
 * if that class changes) and merges tags that turn out to be the same word.
 *
 * It is idempotent and keeps no more than one chunk in memory. Nothing is lost:
 * photos move to the surviving tag and the merged name stays findable as a synonym.
 */
return new class extends Migration
{
    public function up(): void
    {
        $updated = 0;
        $merged = 0;

        DB::table('tags')->orderBy('id')->chunkById(200, function ($tags) use (&$updated, &$merged): void {
            foreach ($tags as $tag) {
                $canonical = self::canonicalSlug((string) $tag->name);
                if ($tag->slug === $canonical) {
                    continue;
                }

                DB::transaction(function () use ($tag, $canonical, &$updated, &$merged): void {
                    $survivor = DB::table('tags')->where('slug', $canonical)->where('id', '!=', $tag->id)->first();
                    if ($survivor === null) {
                        DB::table('tags')->where('id', $tag->id)->update(['slug' => $canonical, 'updated_at' => now()]);
                        $updated++;

                        return;
                    }

                    $this->mergeInto($tag, $survivor, $canonical);
                    $merged++;
                });
            }
        });

        if ($updated > 0 || $merged > 0) {
            Log::info('Tag slugs normalised.', ['updated' => $updated, 'merged' => $merged]);
        }
    }

    public function down(): void
    {
        // The previous slugs were not meaningful and cannot be reconstructed.
    }

    private function mergeInto(object $tag, object $survivor, string $canonical): void
    {
        DB::table('asset_tags')
            ->where('tag_id', $tag->id)
            ->whereNotIn('asset_id', fn ($query) => $query->select('asset_id')->from('asset_tags')->where('tag_id', $survivor->id))
            ->update(['tag_id' => $survivor->id]);
        DB::table('asset_tags')->where('tag_id', $tag->id)->delete();

        DB::table('tag_synonyms')->where('tag_id', $tag->id)->update(['tag_id' => $survivor->id]);

        $taken = DB::table('tag_synonyms')->where('name', $tag->name)->orWhere('slug', $canonical)->exists();
        if (! $taken && $tag->name !== $survivor->name) {
            DB::table('tag_synonyms')->insert([
                'id' => (string) Str::ulid(),
                'tag_id' => $survivor->id,
                'name' => $tag->name,
                'slug' => $canonical,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('tags')->where('id', $tag->id)->delete();
    }

    private static function canonicalSlug(string $name): string
    {
        $slug = Str::slug(trim($name));

        return $slug !== '' ? $slug : 't-'.substr(hash('sha256', $name), 0, 12);
    }
};
