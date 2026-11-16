<?php

namespace App\Services\Review;

use App\Enums\FindingSeverity;
use App\Enums\FindingVerification;

final class Verdict
{
    public function __construct(
        public readonly FindingVerification $verification,
        public readonly ?FindingSeverity $severity,
        public readonly ?int $confidence,
        public readonly string $reason,
        public readonly ?int $learningId = null,
    ) {}
}
