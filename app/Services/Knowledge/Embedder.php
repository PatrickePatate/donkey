<?php

namespace App\Services\Knowledge;

use Laravel\Ai\Embeddings;
use Throwable;

/**
 * Optional text embeddings for similarity search in pgvector. Off unless a provider is configured.
 */
class Embedder
{
    public function isEnabled(): bool
    {
        return filled(config('learning.embeddings.provider'));
    }

    /**
     * @return ?list<float> Null when embeddings are off or the provider failed.
     */
    public function embed(string $text): ?array
    {
        if (! $this->isEnabled() || trim($text) === '') {
            return null;
        }

        try {
            $response = Embeddings::for([mb_substr($text, 0, 8000)])
                ->dimensions((int) config('learning.embeddings.dimensions'))
                ->timeout(30)
                ->generate(
                    provider: config('learning.embeddings.provider'),
                    model: filled(config('learning.embeddings.model')) ? config('learning.embeddings.model') : null,
                );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return array_map(floatval(...), array_values($response->first()));
    }
}
