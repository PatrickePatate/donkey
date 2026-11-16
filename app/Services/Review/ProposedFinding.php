<?php

namespace App\Services\Review;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;

/**
 * A finding returned by the model after validation, before it is stored.
 */
final class ProposedFinding
{
    public function __construct(
        public readonly string $path,
        public readonly int $startLine,
        public readonly int $endLine,
        public readonly FindingCategory $category,
        public readonly FindingSeverity $severity,
        public readonly int $confidence,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $suggestion,
        public readonly string $code,
        public readonly ?string $graphSymbol = null,
        public readonly ?int $graphCallers = null,
        public readonly float $impactBoost = 0.0,
    ) {}

    /**
     * Severity weighted by confidence, raised for code that other functions depend on; 0-100.
     */
    public function riskScore(): int
    {
        return (int) min(100, round($this->severity->weight() * $this->confidence / 100 * (1 + $this->impactBoost)));
    }

    /**
     * Copy with structural impact from the code graph.
     */
    public function withImpact(?string $symbol, ?int $callers, float $boost): self
    {
        return new self(
            $this->path, $this->startLine, $this->endLine, $this->category, $this->severity, $this->confidence,
            $this->title, $this->body, $this->suggestion, $this->code, $symbol, $callers, $boost,
        );
    }

    /**
     * Copy with the verifier's adjusted severity and confidence.
     */
    public function withAssessment(FindingSeverity $severity, int $confidence): self
    {
        return new self(
            $this->path, $this->startLine, $this->endLine, $this->category, $severity, max(0, min(100, $confidence)),
            $this->title, $this->body, $this->suggestion, $this->code, $this->graphSymbol, $this->graphCallers, $this->impactBoost,
        );
    }

    /**
     * Identifies the same issue across runs: file, category and the normalized code it points at.
     * Line numbers and the model's wording are left out on purpose, since both drift between runs.
     */
    public function fingerprint(): string
    {
        $code = strtolower((string) preg_replace('/\s+/', '', $this->code));

        return sha1(implode('|', [$this->path, $this->category->value, $code !== '' ? $code : strtolower($this->title)]));
    }
}
