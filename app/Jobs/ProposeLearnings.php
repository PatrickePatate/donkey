<?php

namespace App\Jobs;

use App\Ai\Agents\LearningSynthesizer;
use App\Enums\AiFeature;
use App\Enums\ConversationRole;
use App\Enums\FindingCategory;
use App\Enums\LearningKind;
use App\Enums\LearningStatus;
use App\Enums\SignalType;
use App\Models\FindingSignal;
use App\Models\Learning;
use App\Models\Repository;
use App\Services\Ai\OrganizationAi;
use App\Services\Usage\UsageMeter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns unprocessed negative feedback on a repository's findings into proposed learnings for admins to approve.
 *
 * Dispatched a few minutes after a dismissal, so several dismissals in a row are looked at together.
 */
class ProposeLearnings implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    /**
     * Weak signals alone (findings merged untouched) need this many before they are worth a look.
     */
    private const MIN_WEAK_SIGNALS = 3;

    public function __construct(public Repository $repository)
    {
        $this->onQueue('default');
    }

    public static function dispatchFor(Repository $repository): void
    {
        self::dispatch($repository)->delay(now()->addMinutes((int) config('learning.synthesis.delay_minutes')));
    }

    public function uniqueId(): string
    {
        return (string) $this->repository->id;
    }

    public function uniqueFor(): int
    {
        return ((int) config('learning.synthesis.delay_minutes') + 10) * 60;
    }

    public function handle(UsageMeter $usage, OrganizationAi $ai): void
    {
        $repository = $this->repository->fresh('organization');

        if ($repository === null) {
            return;
        }

        $signals = FindingSignal::query()
            ->where('repository_id', $repository->id)
            ->whereNull('processed_at')
            ->whereIn('type', SignalType::negativeValues())
            ->with(['finding.conversationMessages'])
            ->latest('id')
            ->limit((int) config('learning.synthesis.max_signals'))
            ->get();

        $strong = $signals->filter(fn (FindingSignal $signal): bool => $signal->type->isStrongNegative());

        if (($strong->isEmpty() && $signals->count() < self::MIN_WEAK_SIGNALS) || $usage->isOverBudget($repository->organization)) {
            return;
        }

        $existing = Learning::query()
            ->forRepository($repository)
            ->whereIn('status', [LearningStatus::Active, LearningStatus::Proposed, LearningStatus::Rejected])
            ->latest('id')
            ->limit(60)
            ->get();

        $response = LearningSynthesizer::make()->prompt(
            $this->prompt($signals, $existing),
            provider: $ai->provider($repository->organization),
            model: $repository->organization->modelFor('review'),
            timeout: 180,
        );

        $usage->record($repository->organization, AiFeature::Learning, $repository->organization->aiProvider(), $repository->organization->modelFor('review'), $response->usage, $repository);

        $findingIds = $signals->pluck('review_finding_id')->unique()->values();

        foreach ($response['learnings'] ?? [] as $raw) {
            if (is_array($raw)) {
                $this->apply($repository, $raw, $existing, $findingIds);
            }
        }

        FindingSignal::query()->whereKey($signals->modelKeys())->update(['processed_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  Collection<int, Learning>  $existing
     * @param  Collection<int, int>  $findingIds
     */
    private function apply(Repository $repository, array $raw, Collection $existing, Collection $findingIds): void
    {
        $sources = collect($raw['finding_ids'] ?? [])->map(fn ($id): int => (int) $id)->intersect($findingIds)->values()->all();
        $reinforced = $existing->firstWhere('id', (int) ($raw['reinforces'] ?? 0));

        if ($reinforced !== null) {
            if ($reinforced->status === LearningStatus::Rejected) {
                return;
            }

            $reinforced->update(['source_finding_ids' => array_values(array_unique([...($reinforced->source_finding_ids ?? []), ...$sources]))]);

            if ($reinforced->status === LearningStatus::Active) {
                $reinforced->reinforce();
            }

            return;
        }

        $kind = LearningKind::tryFrom((string) ($raw['kind'] ?? ''));
        $body = trim((string) ($raw['body'] ?? ''));

        if ($kind === null || $body === '' || $sources === []) {
            return;
        }

        $repository->organization->learnings()->create([
            'repository_id' => ($raw['scope'] ?? 'repository') === 'organization' ? null : $repository->id,
            'kind' => $kind,
            'status' => LearningStatus::Proposed,
            'body' => Str::limit($body, 1000),
            'rationale' => Str::limit(trim((string) ($raw['rationale'] ?? '')), 1000) ?: null,
            'category' => FindingCategory::tryFrom((string) ($raw['category'] ?? '')),
            'path_glob' => filled($raw['path_glob'] ?? null) ? Str::limit(trim((string) $raw['path_glob']), 250, '') : null,
            'source_finding_ids' => $sources,
            'weight' => 1,
        ]);
    }

    /**
     * @param  Collection<int, FindingSignal>  $signals
     * @param  Collection<int, Learning>  $existing
     */
    private function prompt(Collection $signals, Collection $existing): string
    {
        $sections = [];

        if ($existing->isNotEmpty()) {
            $sections[] = "<existing_learnings>\n".$existing
                ->map(fn (Learning $learning): string => "- id {$learning->id} [{$learning->status->value}, {$learning->kind->value}".($learning->path_glob !== null ? ", {$learning->path_glob}" : '')."] {$learning->body}")
                ->implode("\n")
                ."\nRejected learnings were turned down by the team: do not propose them again.\n</existing_learnings>";
        }

        foreach ($signals->groupBy('review_finding_id') as $group) {
            $finding = $group->first()->finding;
            $thread = $finding->conversationMessages
                ->map(fn ($message): string => ($message->role === ConversationRole::Bot ? 'Reviewer' : "@{$message->author_login}").': '.Str::limit($message->body, 1500))
                ->implode("\n");

            $sections[] = implode("\n", array_filter([
                "<finding id=\"{$finding->id}\">",
                'Feedback: '.$group->map(fn (FindingSignal $signal): string => $signal->type->label().($signal->actor_login !== null ? " by @{$signal->actor_login}" : ''))->implode(', '),
                "{$finding->severity->value} {$finding->category->value} in {$finding->file_path} {$finding->lineLabel()}",
                "Title: {$finding->title}",
                Str::limit($finding->body, 2000),
                $finding->code !== null ? "Code:\n```\n".Str::limit($finding->code, 1500)."\n```" : null,
                $thread !== '' ? "<thread>\n{$thread}\n</thread>" : null,
                '</finding>',
            ]));
        }

        return implode("\n\n", $sections);
    }
}
