<?php

namespace App\Services\Knowledge;

use App\Enums\LearningKind;
use App\Models\Learning;
use App\Models\RepositoryMemory;
use Illuminate\Support\Collection;

/**
 * What the team taught the reviewer that matters for one pull request.
 */
final class ReviewKnowledge
{
    /**
     * @param  Collection<int, RepositoryMemory>  $memories
     * @param  Collection<int, Learning>  $learnings
     */
    public function __construct(
        public readonly Collection $memories,
        public readonly Collection $learnings,
    ) {}

    public static function empty(): self
    {
        return new self(collect(), collect());
    }

    public function isEmpty(): bool
    {
        return $this->memories->isEmpty() && $this->learnings->isEmpty();
    }

    /**
     * @return Collection<int, Learning>
     */
    public function falsePositives(): Collection
    {
        return $this->learnings->where('kind', LearningKind::FalsePositive)->values();
    }

    /**
     * Prompt section for the reviewer. Ids let the verifier cite which learning a finding matches.
     */
    public function promptSection(): string
    {
        if ($this->isEmpty()) {
            return '';
        }

        $lines = [
            '<team_knowledge>',
            'What this team told you about the repository and learned from feedback on earlier reviews. Follow it: do not',
            'report findings that a false-positive learning describes, and apply conventions and preferences when judging the code.',
        ];

        if ($this->memories->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Repository memory:';

            foreach ($this->memories as $memory) {
                $lines[] = '- '.($memory->path_glob !== null ? "[{$memory->path_glob}] " : '').$memory->body;
            }
        }

        if ($this->learnings->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Learnings:';

            foreach ($this->learnings as $learning) {
                $scope = array_filter([$learning->category?->value, $learning->path_glob]);
                $lines[] = "- (learning {$learning->id}, {$learning->kind->value}".($scope !== [] ? ', '.implode(', ', $scope) : '').") {$learning->body}";
            }
        }

        $lines[] = '</team_knowledge>';

        return implode("\n", $lines);
    }
}
