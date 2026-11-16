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

    /**
     * Combine the general review with agent passes; the first finding of each fingerprint wins.
     */
    public function merge(self $other): self
    {
        $findings = [];

        foreach ([...$this->findings, ...$other->findings] as $finding) {
            $findings[$finding->fingerprint()] ??= $finding;
        }

        return new self(
            array_values($findings),
            [...$this->summaries, ...$other->summaries],
            max($this->riskScore, $other->riskScore),
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->discarded + $other->discarded,
        );
    }
}
