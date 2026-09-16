<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

/**
 * Any provider capable of producing image and text embeddings in one shared
 * vector space. Only providers that genuinely document a shared multimodal
 * space may implement this (Local/External custom protocol, Gemini
 * gemini-embedding-2, and allowlisted OpenRouter multimodal embedding
 * models). OpenAI text embeddings and Anthropic (no native embeddings) do
 * not implement this contract.
 */
interface EmbeddingProvider
{
    /**
     * @param  array<string, mixed>  $context  optional 'model' override and 'timeout_seconds'
     * @return array{embedding: list<float|int>, model_space: string, dimensions: int}
     */
    public function embedImage(string $imageBytes, array $context = []): array;

    /**
     * @param  array<string, mixed>  $context  optional 'model' override and 'timeout_seconds'
     * @return array{embedding: list<float|int>, model_space: string, dimensions: int}
     */
    public function embedText(string $query, array $context = []): array;
}
