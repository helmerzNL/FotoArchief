<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Services;

use App\Modules\Catalogue\Models\Tag;
use App\Modules\Catalogue\Models\TagSynonym;
use App\Modules\Catalogue\Support\TagSlug;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Normalizer;

/**
 * Finds or creates the tag a name refers to. A name matches, in this order, a tag
 * with the same slug, a synonym with the same slug, or a tag whose name differs
 * only in case. Only when none match is a new tag created.
 */
class TagResolver
{
    private const MAX_NAME_LENGTH = 100;

    public function find(string $name): ?Tag
    {
        $name = $this->clean($name);
        if ($name === '') {
            return null;
        }
        $slug = TagSlug::for($name);

        $tag = Tag::query()->where('slug', $slug)->first();
        if ($tag instanceof Tag) {
            return $tag;
        }

        $synonym = TagSynonym::query()->where('slug', $slug)->first();
        if ($synonym instanceof TagSynonym) {
            return $synonym->tag;
        }

        return Tag::query()->where(DB::raw('lower(name)'), mb_strtolower($name))->first();
    }

    public function resolve(string $name): Tag
    {
        $name = $this->clean($name);
        $existing = $this->find($name);
        if ($existing instanceof Tag) {
            return $existing;
        }

        try {
            // A nested transaction is a savepoint, so losing a race for the unique
            // slug does not poison an outer PostgreSQL transaction.
            return DB::transaction(fn (): Tag => Tag::query()->create(['name' => $name, 'slug' => TagSlug::for($name)]));
        } catch (UniqueConstraintViolationException $exception) {
            return $this->find($name) ?? throw $exception;
        }
    }

    public function clean(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if (class_exists(Normalizer::class)) {
            $normalised = Normalizer::normalize($name);
            $name = $normalised === false ? $name : $normalised;
        }

        return mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }
}
