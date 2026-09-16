<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

/**
 * Any provider capable of describing/tagging one image. Implemented by the
 * existing custom-protocol Local/External providers and by every native
 * provider adapter (OpenAI, Anthropic, Gemini, OpenRouter).
 */
interface ImageAnalysisProvider
{
    /**
     * @param  array<string, mixed>  $context  asset/file identifiers plus an
     *                                         optional 'model' override and
     *                                         'timeout_seconds'.
     * @return array<string, mixed> must contain string 'description' and list<string> 'tags'
     */
    public function analyzeImage(string $imageBytes, array $context): array;
}
