<?php

namespace App\Services\Review;

use App\Enums\FindingSeverity;
use App\Models\Organization;
use App\Models\ReviewAgent;
use App\Services\Graph\GraphContext;

class ModelRouter
{
    /**
     * @param  list<FilePatch>  $chunk
     */
    public function reviewModel(?Organization $organization, ?ReviewAgent $agent, array $chunk, ?GraphContext $graph): string
    {
        if (filled($agent?->model)) {
            return (string) $agent->model;
        }

        if (! $organization?->eco_mode || $this->hasHighGraphRisk($graph) || $this->patchCharacters($chunk) > (int) config('reviews.eco.small_chunk_characters')) {
            return (string) config('reviews.model');
        }

        return (string) config('reviews.eco.model');
    }

    /**
     * @param  array<int, ProposedFinding>  $findings
     */
    public function verifierModel(Organization $organization, array $findings): string
    {
        if (! $organization->eco_mode || count($findings) > (int) config('reviews.eco.verifier_max_findings')) {
            return (string) config('reviews.verifier.model');
        }

        foreach ($findings as $finding) {
            if (in_array($finding->severity, [FindingSeverity::Critical, FindingSeverity::High], true)) {
                return (string) config('reviews.verifier.model');
            }
        }

        return (string) config('reviews.eco.model');
    }

    /**
     * @param  list<FilePatch>  $chunk
     */
    private function patchCharacters(array $chunk): int
    {
        return array_sum(array_map(fn (FilePatch $file): int => strlen($file->patch), $chunk));
    }

    private function hasHighGraphRisk(?GraphContext $graph): bool
    {
        return in_array(strtoupper((string) $graph?->riskLevel), ['HIGH', 'CRITICAL'], true);
    }
}
