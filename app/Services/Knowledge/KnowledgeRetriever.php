<?php

namespace App\Services\Knowledge;

use App\Enums\LearningStatus;
use App\Models\Learning;
use App\Models\PullRequest;
use App\Models\RepositoryMemory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Picks the memories and learnings worth putting in front of the reviewer for a pull request.
 *
 * Pinned memories always come first. The rest must concern a changed file (or have no path), and are ranked by
 * similarity to the pull request when embeddings are on, otherwise by weight and recency, within a character budget.
 */
class KnowledgeRetriever
{
    /**
     * Candidates fetched before path filtering and the character budget.
     */
    private const CANDIDATES = 100;

    public function __construct(private Embedder $embedder) {}

    /**
     * @param  list<string>  $paths  Files changed by the pull request.
     */
    public function forPullRequest(PullRequest $pullRequest, array $paths): ReviewKnowledge
    {
        $repository = $pullRequest->repository;
        $query = $this->embedder->isEnabled() ? $this->embedder->embed($this->queryText($pullRequest, $paths)) : null;

        $memories = $repository->memories()
            ->orderByDesc('pinned')
            ->when($query !== null, fn (Builder $builder) => $builder->orderByRaw('embedding is null')->orderByVectorDistance('embedding', $query))
            ->latest('id')
            ->limit(self::CANDIDATES)
            ->get()
            ->filter(fn (RepositoryMemory $memory): bool => $memory->pinned || $memory->appliesToAny($paths))
            ->take((int) config('learning.max_memories'));

        $learnings = Learning::query()
            ->forRepository($repository)
            ->where('status', LearningStatus::Active)
            ->when($query !== null, fn (Builder $builder) => $builder->orderByRaw('embedding is null')->orderByVectorDistance('embedding', $query))
            ->orderByDesc('weight')
            ->latest('id')
            ->limit(self::CANDIDATES)
            ->get()
            ->filter(fn (Learning $learning): bool => $learning->appliesToAny($paths))
            ->take((int) config('learning.max_learnings'));

        [$memories, $learnings] = $this->withinBudget($memories->values(), $learnings->values());

        if ($memories->isNotEmpty()) {
            RepositoryMemory::query()->whereKey($memories->modelKeys())->update(['last_used_at' => now()]);
            RepositoryMemory::query()->whereKey($memories->modelKeys())->increment('times_used');
        }

        return new ReviewKnowledge($memories, $learnings);
    }

    /**
     * @param  list<string>  $paths
     */
    private function queryText(PullRequest $pullRequest, array $paths): string
    {
        return implode("\n", [$pullRequest->title, (string) $pullRequest->body, ...array_slice($paths, 0, 50)]);
    }

    /**
     * Keep memories first, then learnings, until the character budget runs out.
     *
     * @param  Collection<int, RepositoryMemory>  $memories
     * @param  Collection<int, Learning>  $learnings
     * @return array{0: \Illuminate\Database\Eloquent\Collection<int, RepositoryMemory>, 1: Collection<int, Learning>}
     */
    private function withinBudget(Collection $memories, Collection $learnings): array
    {
        $budget = (int) config('learning.max_characters');
        $keptMemories = new \Illuminate\Database\Eloquent\Collection;
        $keptLearnings = collect();

        foreach ($memories as $memory) {
            if (($budget -= mb_strlen($memory->body) + 10) < 0) {
                break;
            }

            $keptMemories->push($memory);
        }

        foreach ($learnings as $learning) {
            if (($budget -= mb_strlen($learning->body) + 40) < 0) {
                break;
            }

            $keptLearnings->push($learning);
        }

        return [$keptMemories, $keptLearnings];
    }
}
