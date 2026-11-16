<?php

namespace App\Services\Usage;

use App\Enums\AiFeature;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use stdClass;

/**
 * A month of an organization's AI spend, broken down for the usage page.
 */
class UsageReport
{
    /**
     * @return array{
     *     cost: int, calls: int, input_tokens: int, output_tokens: int, previous_cost: int, projected: ?int,
     *     days: list<array{date: string, cost: int}>,
     *     features: list<array{label: string, cost: int, calls: int}>,
     *     repositories: list<array{label: string, cost: int, calls: int}>,
     *     models: list<array{label: string, cost: int, input_tokens: int, output_tokens: int}>,
     *     pull_requests: list<array{id: int, label: string, cost: int}>
     * }
     */
    public function forMonth(Organization $organization, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $usage = fn () => $organization->aiUsages()->whereBetween('ai_usages.created_at', [$start, $end]);

        $totals = $usage()->toBase()
            ->selectRaw('coalesce(sum(cost_micros), 0) as cost, count(*) as calls, coalesce(sum(input_tokens), 0) as input_tokens, coalesce(sum(output_tokens), 0) as output_tokens')
            ->first();

        $daily = $usage()->toBase()
            ->selectRaw("to_char(created_at, 'YYYY-MM-DD') as day, sum(cost_micros) as cost")
            ->groupBy('day')
            ->pluck('cost', 'day');

        $days = collect(range(1, $start->daysInMonth))
            ->map(fn (int $day): string => $start->copy()->day($day)->toDateString())
            ->filter(fn (string $date): bool => $date <= now()->toDateString() || ! $start->isCurrentMonth())
            ->map(fn (string $date): array => ['date' => $date, 'cost' => (int) ($daily[$date] ?? 0)])
            ->values()
            ->all();

        $isCurrent = $start->isSameMonth(now());
        $elapsed = $isCurrent ? max(1, now()->day) : $start->daysInMonth;

        return [
            'cost' => (int) $totals->cost,
            'calls' => (int) $totals->calls,
            'input_tokens' => (int) $totals->input_tokens,
            'output_tokens' => (int) $totals->output_tokens,
            'previous_cost' => (int) $organization->aiUsages()
                ->whereBetween('created_at', [$start->copy()->subMonthNoOverflow(), $start->copy()->subMonthNoOverflow()->endOfMonth()])
                ->sum('cost_micros'),
            'projected' => $isCurrent ? (int) round($totals->cost / $elapsed * $start->daysInMonth) : null,
            'days' => $days,
            'features' => $this->rows($usage()->toBase()
                ->selectRaw('feature as label, sum(cost_micros) as cost, count(*) as calls')
                ->groupBy('feature')
                ->orderByDesc('cost')
                ->get())
                ->map(fn (array $row): array => [...$row, 'label' => AiFeature::tryFrom($row['label'])?->label() ?? $row['label']])
                ->all(),
            'repositories' => $this->rows($usage()
                ->leftJoin('repositories', 'repositories.id', '=', 'ai_usages.repository_id')
                ->toBase()
                ->selectRaw("coalesce(repositories.full_name, 'Other') as label, sum(cost_micros) as cost, count(*) as calls")
                ->groupBy('label')
                ->orderByDesc('cost')
                ->limit(10)
                ->get())->all(),
            'models' => $usage()->toBase()
                ->selectRaw('model as label, sum(cost_micros) as cost, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens')
                ->groupBy('model')
                ->orderByDesc('cost')
                ->get()
                ->map(fn (object $row): array => [
                    'label' => (string) $row->label,
                    'cost' => (int) $row->cost,
                    'input_tokens' => (int) $row->input_tokens,
                    'output_tokens' => (int) $row->output_tokens,
                ])
                ->all(),
            'pull_requests' => $usage()
                ->join('pull_requests', 'pull_requests.id', '=', 'ai_usages.pull_request_id')
                ->join('repositories as pr_repositories', 'pr_repositories.id', '=', 'pull_requests.repository_id')
                ->toBase()
                ->selectRaw("pull_requests.id as id, pr_repositories.full_name || ' #' || pull_requests.number || ' · ' || pull_requests.title as label, sum(cost_micros) as cost")
                ->groupBy('pull_requests.id', 'pr_repositories.full_name', 'pull_requests.number', 'pull_requests.title')
                ->orderByDesc('cost')
                ->limit(10)
                ->get()
                ->map(fn (object $row): array => ['id' => (int) $row->id, 'label' => (string) $row->label, 'cost' => (int) $row->cost])
                ->all(),
        ];
    }

    /**
     * Format millionths of a dollar for display.
     */
    public static function money(int $micros): string
    {
        if ($micros > 0 && $micros < 10_000) {
            return '<$0.01';
        }

        return '$'.number_format($micros / 1_000_000, 2);
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return Collection<int, array{label: string, cost: int, calls: int}>
     */
    private function rows(Collection $rows): Collection
    {
        return $rows->map(fn (stdClass $row): array => ['label' => (string) $row->label, 'cost' => (int) $row->cost, 'calls' => (int) $row->calls]);
    }
}
