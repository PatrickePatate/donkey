<?php

namespace App\Services\Review;

/**
 * Merged model output for every chunk of a review run.
 */
final class ReviewAnalysis
{
    /**
     * @param  list<ProposedFinding>  $findings
     * @param  list<string>  $summaries
     */
    public function __construct(
        public readonly array $findings,
        public readonly array $summaries,
        public readonly int $riskScore,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $discarded,
    ) {}
}
