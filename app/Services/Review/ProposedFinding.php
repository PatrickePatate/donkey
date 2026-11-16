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
    ) {}

    /**
     * Severity weighted by confidence, 0-100.
     */
    public function riskScore(): int
    {
        return (int) round($this->severity->weight() * $this->confidence / 100);
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
