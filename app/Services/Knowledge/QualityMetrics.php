<?php

namespace App\Services\Knowledge;

use App\Enums\FindingStatus;
use App\Enums\SignalType;
use App\Models\Organization;
use App\Models\ReviewFinding;
use Illuminate\Database\Eloquent\Builder;

/**
 * How useful the reviewer's comments were, from the feedback collected on them.
 */
class QualityMetrics
{
    /**
     * Statuses of findings that were posted on GitHub at some point.
     */
    private const POSTED = [FindingStatus::Published, FindingStatus::Dismissed, FindingStatus::Outdated];

    /**
     * @return array{
     *     posted: int, accepted: int, rejected: int, ignored: int, acceptance_rate: ?int,
     *     filtered_by_learnings: int, rejected_by_verifier: int,
     *     by_category: list<array{label: string, posted: int, accepted: int, rejected: int, ignored: int}>,
     *     by_reviewer: list<array{label: string, posted: int, accepted: int, rejected: int, ignored: int}>
     * }
     */
    public function forOrganization(Organization $organization, int $days = 30): array
    {
        $totals = $this->posted($organization, $days)->toBase()->selectRaw($this->aggregates())->first();

        $byCategory = $this->posted($organization, $days)
            ->toBase()
            ->selectRaw('review_findings.category as label, '.$this->aggregates())
            ->groupBy('review_findings.category')
            ->orderByDesc('posted')
            ->get();

        $byReviewer = $this->posted($organization, $days)
            ->leftJoin('review_agents', 'review_agents.id', '=', 'review_findings.review_agent_id')
            ->toBase()
            ->selectRaw("coalesce(review_agents.name, 'Donkey') as label, ".$this->aggregates())
            ->groupBy('label')
            ->orderByDesc('posted')
            ->get();

        $withFeedback = (int) $totals->accepted + (int) $totals->rejected + (int) $totals->ignored;

        $recent = ReviewFinding::query()
            ->whereHas('pullRequest.repository', fn (Builder $query) => $query->where('organization_id', $organization->id))
            ->whereHas('reviewRun', fn (Builder $query) => $query->where('is_dry_run', false))
            ->where('review_findings.created_at', '>=', now()->subDays($days));

        return [
            'posted' => (int) $totals->posted,
            'accepted' => (int) $totals->accepted,
            'rejected' => (int) $totals->rejected,
            'ignored' => (int) $totals->ignored,
            'acceptance_rate' => $withFeedback > 0 ? (int) round(100 * (int) $totals->accepted / $withFeedback) : null,
            'filtered_by_learnings' => (clone $recent)->whereNotNull('learning_id')->count(),
            'rejected_by_verifier' => (clone $recent)->where('status', FindingStatus::Rejected)->count(),
            'by_category' => $byCategory->map(fn (object $row): array => $this->row($row, ucfirst((string) $row->label)))->all(),
            'by_reviewer' => $byReviewer->map(fn (object $row): array => $this->row($row, (string) $row->label))->all(),
        ];
    }

    /**
     * @return Builder<ReviewFinding>
     */
    private function posted(Organization $organization, int $days): Builder
    {
        return ReviewFinding::query()
            ->join('review_runs', 'review_runs.id', '=', 'review_findings.review_run_id')
            ->join('pull_requests', 'pull_requests.id', '=', 'review_findings.pull_request_id')
            ->join('repositories', 'repositories.id', '=', 'pull_requests.repository_id')
            ->where('repositories.organization_id', $organization->id)
            ->where('review_runs.is_dry_run', false)
            ->whereIn('review_findings.status', self::POSTED)
            ->where('review_findings.created_at', '>=', now()->subDays($days));
    }

    /**
     * A finding counts once: accepted if anyone acted on it, otherwise rejected or ignored.
     */
    private function aggregates(): string
    {
        $positive = $this->quoted(SignalType::positiveValues());
        $strong = $this->quoted([SignalType::Dismissed->value, SignalType::ThumbsDown->value]);
        $has = fn (string $types): string => "exists (select 1 from finding_signals s where s.review_finding_id = review_findings.id and s.type in ({$types}))";
        $ignored = $this->quoted([SignalType::IgnoredOnMerge->value]);

        return implode(', ', [
            'count(*) as posted',
            "count(*) filter (where {$has($positive)}) as accepted",
            "count(*) filter (where not {$has($positive)} and {$has($strong)}) as rejected",
            "count(*) filter (where not {$has($positive)} and not {$has($strong)} and {$has($ignored)}) as ignored",
        ]);
    }

    /**
     * @param  list<string>  $values  Enum values only, never user input.
     */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $value): string => "'{$value}'", $values));
    }

    /**
     * @return array{label: string, posted: int, accepted: int, rejected: int, ignored: int}
     */
    private function row(object $row, string $label): array
    {
        return [
            'label' => $label,
            'posted' => (int) $row->posted,
            'accepted' => (int) $row->accepted,
            'rejected' => (int) $row->rejected,
            'ignored' => (int) $row->ignored,
        ];
    }
}
