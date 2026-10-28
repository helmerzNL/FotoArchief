<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Services\TagResolver;
use App\Modules\Catalogue\Support\TagSlug;
use Illuminate\Support\Str;
use Normalizer;

/**
 * Decides which of the tags a provider returned become suggestions. A suggestion
 * is only a proposal a person still has to accept, but every proposal costs review
 * time, so the list is cleaned, de-duplicated, limited to confident tags and
 * stripped of what the photo already carries.
 */
class AiTagSuggestionSelector
{
    private const MAX_LENGTH = 60;

    public function __construct(private readonly TagResolver $tags) {}

    /**
     * @param  array<string, mixed>  $analysis  provider result: 'tags' (list of names) and optionally
     *                                          'tag_confidences' (lower-case name => 0..1)
     * @return list<array{value: string, confidence: float|null}>
     */
    public function select(Asset $asset, array $analysis): array
    {
        $max = max(1, (int) config('ai.tag_suggestions.max_per_image', 8));
        $minimum = (float) config('ai.tag_suggestions.min_confidence', 0.5);
        $confidences = is_array($analysis['tag_confidences'] ?? null) ? $analysis['tag_confidences'] : [];
        $present = $asset->tags()->pluck('tags.id')->all();

        $candidates = [];
        foreach (array_filter(is_array($analysis['tags'] ?? null) ? $analysis['tags'] : [], 'is_string') as $raw) {
            $value = $this->normalise($raw);
            if ($value === '') {
                continue;
            }
            $confidence = $confidences[mb_strtolower(trim($raw))] ?? $confidences[$value] ?? null;
            $confidence = is_numeric($confidence) ? min(1.0, max(0.0, (float) $confidence)) : null;
            // Unknown confidence is kept (local and external providers report none);
            // a known low confidence is not worth a reviewer's time.
            if ($confidence !== null && $confidence < $minimum) {
                continue;
            }
            $key = TagSlug::for($value);
            if (isset($candidates[$key])) {
                continue;
            }
            $known = $this->tags->find($value);
            if ($known !== null && in_array($known->id, $present, true)) {
                continue;
            }
            $candidates[$key] = ['value' => $value, 'confidence' => $confidence];
        }

        $ordered = array_values($candidates);
        // Most certain first; unknown confidence keeps the provider's order after them.
        usort($ordered, fn (array $a, array $b): int => ($b['confidence'] ?? -1.0) <=> ($a['confidence'] ?? -1.0));

        return array_slice($ordered, 0, $max);
    }

    private function normalise(string $raw): string
    {
        $value = Str::lower(trim((string) preg_replace('/\s+/u', ' ', $raw)));
        $value = trim($value, " \t\n\r\0\x0B.,;:!?\"'()[]#-_");
        if (class_exists(Normalizer::class)) {
            $normalised = Normalizer::normalize($value);
            $value = $normalised === false ? $value : $normalised;
        }
        $value = mb_substr($value, 0, self::MAX_LENGTH);

        // No letters or digits means nothing a person could confirm as a tag.
        return Str::slug($value) === '' ? '' : $value;
    }
}
