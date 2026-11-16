<?php

namespace App\Services\Graph;

/**
 * Structural context for one review: a prompt block for the model plus structured impact data.
 */
final class GraphContext
{
    /**
     * @param  list<ChangedFunction>  $changedFunctions
     */
    public function __construct(
        public readonly string $prompt,
        public readonly ?string $riskLevel,
        public readonly ?int $riskScore,
        public readonly array $changedFunctions,
        public readonly int $testGaps,
        public readonly int $impactedFunctions,
        public readonly int $impactedFiles,
        public readonly int $affectedFlows,
    ) {}

    public function functionAt(string $path, int $line): ?ChangedFunction
    {
        $matches = array_filter($this->changedFunctions, fn (ChangedFunction $function): bool => $function->contains($path, $line));

        // The innermost (shortest) function wins, e.g. a closure inside a method.
        usort($matches, fn (ChangedFunction $a, ChangedFunction $b): int => ($a->lineEnd - $a->lineStart) <=> ($b->lineEnd - $b->lineStart));

        return $matches[0] ?? null;
    }

    /**
     * Risk multiplier for a finding inside a changed function: log-scaled by caller count,
     * plus a bump when the function's contract changed and someone depends on it.
     */
    public function impactBoost(?ChangedFunction $function): float
    {
        if ($function === null || $function->callers === 0) {
            return 0.0;
        }

        $max = (float) config('reviews.max_impact_boost');
        $boost = $max * log(1 + $function->callers, 2) / log(17, 2);

        if ($function->contractChanges !== []) {
            $boost += $max / 3;
        }

        return round(min($max, $boost), 3);
    }

    /**
     * @return array{changed_functions: list<array<string, mixed>>, test_gaps: int, impacted_functions: int, impacted_files: int, affected_flows: int}
     */
    public function summary(): array
    {
        return [
            'changed_functions' => array_map(fn (ChangedFunction $function): array => $function->toArray(), array_slice($this->changedFunctions, 0, 50)),
            'test_gaps' => $this->testGaps,
            'impacted_functions' => $this->impactedFunctions,
            'impacted_files' => $this->impactedFiles,
            'affected_flows' => $this->affectedFlows,
        ];
    }
}
