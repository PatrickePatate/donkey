<?php

namespace App\Services\Graph;

/**
 * A function or method changed by the pull request, as kodus-graph sees it.
 */
final class ChangedFunction
{
    /**
     * @param  list<array{field: string, old: string, new: string}>  $contractChanges
     */
    public function __construct(
        public readonly string $qualifiedName,
        public readonly string $name,
        public readonly string $filePath,
        public readonly int $lineStart,
        public readonly int $lineEnd,
        public readonly int $callers,
        public readonly bool $isTested,
        public readonly array $contractChanges,
        public readonly ?string $callerImpact,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromKodusGraph(array $data): self
    {
        return new self(
            qualifiedName: (string) ($data['qualified_name'] ?? ''),
            name: (string) ($data['name'] ?? $data['qualified_name'] ?? ''),
            filePath: (string) ($data['file_path'] ?? ''),
            lineStart: (int) ($data['line_start'] ?? 0),
            lineEnd: (int) ($data['line_end'] ?? 0),
            callers: count($data['callers'] ?? []),
            isTested: (bool) ($data['has_test_coverage'] ?? false),
            contractChanges: array_values(array_map(fn (array $change): array => [
                'field' => (string) ($change['field'] ?? ''),
                'old' => (string) ($change['old_value'] ?? ''),
                'new' => (string) ($change['new_value'] ?? ''),
            ], array_filter($data['contract_diffs'] ?? [], 'is_array'))),
            callerImpact: filled($data['caller_impact'] ?? null) ? (string) $data['caller_impact'] : null,
        );
    }

    public function contains(string $path, int $line): bool
    {
        return $this->filePath === $path && $line >= $this->lineStart && $line <= $this->lineEnd;
    }

    /**
     * @return array{name: string, symbol: string, file: string, start: int, end: int, callers: int, tested: bool, contract: list<array{field: string, old: string, new: string}>, impact: ?string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'symbol' => $this->qualifiedName,
            'file' => $this->filePath,
            'start' => $this->lineStart,
            'end' => $this->lineEnd,
            'callers' => $this->callers,
            'tested' => $this->isTested,
            'contract' => $this->contractChanges,
            'impact' => $this->callerImpact,
        ];
    }
}
