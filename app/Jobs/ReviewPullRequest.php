<?php

namespace App\Jobs;

use App\Enums\FindingStatus;
use App\Enums\FindingVerification;
use App\Enums\ReviewRunStatus;
use App\Enums\ReviewStage;
use App\Enums\SignalType;
use App\Events\ReviewRunUpdated;
use App\Models\Learning;
use App\Models\ReviewAgent;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
use App\Services\GitHub\PullRequestClient;
use App\Services\Graph\GraphContext;
use App\Services\Graph\GraphContextProvider;
use App\Services\Knowledge\KnowledgeRetriever;
use App\Services\Knowledge\ReviewKnowledge;
use App\Services\Knowledge\SignalRecorder;
use App\Services\Review\FilePatch;
use App\Services\Review\FindingVerifierService;
use App\Services\Review\ProposedFinding;
use App\Services\Review\ReviewAnalysis;
use App\Services\Review\ReviewAnalyzer;
use App\Services\Review\ReviewContext;
use App\Services\Review\ReviewContextBuilder;
use App\Services\Review\ReviewPublisher;
use App\Services\Usage\UsageMeter;
use App\Support\TransientFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Runs one review: collect context, ask the model, validate and gate findings, publish to GitHub.
 */
class ReviewPullRequest implements ShouldQueue
{
    use Queueable;

    /**
     * Attempts only cover waiting for another review of the same PR; failures are handled in handle().
     */
    public int $tries = 40;

    public int $maxExceptions = 1;

    public int $timeout = 900;

    /**
     * How many times a run is retried when GitHub or the AI provider is rate limiting or overloaded.
     */
    private const MAX_TRANSIENT_ATTEMPTS = 6;

    public function __construct(public ReviewRun $run)
    {
        $this->onQueue('reviews');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("pull-request:{$this->run->pull_request_id}"))->releaseAfter(15)->expireAfter(1000),
        ];
    }

    public function handle(
        ReviewContextBuilder $contextBuilder,
        GraphContextProvider $graphProvider,
        ReviewAnalyzer $analyzer,
        FindingVerifierService $verifier,
        ReviewPublisher $publisher,
        PullRequestClient $client,
        KnowledgeRetriever $knowledgeRetriever,
        SignalRecorder $signals,
        UsageMeter $usage,
    ): void {
        $run = $this->run->fresh(['agent', 'pullRequest.repository.organization', 'pullRequest.repository.installation']);

        if ($run === null || $run->status->isFinished()) {
            return;
        }

        $pullRequest = $run->pullRequest;

        if ($this->isSuperseded($run)) {
            $this->finish($run, ReviewRunStatus::Superseded, 'Newer commits were pushed before this review started.');

            return;
        }

        if (! $pullRequest->isOpen()) {
            $this->finish($run, ReviewRunStatus::Skipped, 'The pull request is no longer open.');

            return;
        }

        if ($usage->isOverBudget($pullRequest->repository->organization)) {
            $this->finish($run, ReviewRunStatus::Skipped, 'The organization reached its monthly AI budget.');

            if (! $run->is_dry_run) {
                $run->update(['github_check_run_id' => $client->createCheckRun($pullRequest, config('reviews.check_run_name'), $run->head_sha, route('pull-requests.show', $pullRequest))]);
                $this->completeCheck($client, $run, 'skipped', 'Monthly AI budget reached', 'Reviews resume next month, or when an owner raises the budget in Donkey.');
            }

            return;
        }

        $run->update([
            'status' => ReviewRunStatus::Running,
            'started_at' => now(),
            'provider' => config('reviews.provider'),
            'model' => filled($run->agent?->model) ? $run->agent->model : config('reviews.model'),
            'risk_threshold' => $pullRequest->repository->effectiveRiskThreshold(),
        ]);

        // A retry after GitHub or the AI provider pushed back starts the analysis over.
        $run->findings()->delete();

        if (! $run->is_dry_run && $run->github_check_run_id === null) {
            $checkRunName = config('reviews.check_run_name').($run->agent !== null ? " · {$run->agent->name}" : '');
            $checkRunId = $client->createCheckRun($pullRequest, $checkRunName, $run->head_sha, route('pull-requests.show', ['pullRequest' => $pullRequest, 'run' => $run->id]));
            $run->update(['github_check_run_id' => $checkRunId]);
        }

        try {
            $this->stage($run, ReviewStage::Collecting);
            $context = $contextBuilder->build($run);

            if ($run->agent !== null) {
                $agent = $run->agent;
                $context = $context->onlyFiles(fn (FilePatch $file): bool => $agent->matchesPath($file->path));
            }

            $run->update(['base_sha' => $context->baseSha, 'files_reviewed' => count($context->files)]);

            if ($context->files === []) {
                if ($this->movesReviewBaseline($run)) {
                    $pullRequest->update(['last_reviewed_sha' => $run->head_sha]);
                }

                $reason = $run->agent !== null ? "No changed files match the {$run->agent->name} agent." : 'No reviewable changes.';
                $this->finish($run, ReviewRunStatus::Skipped, $reason);
                $this->completeCheck($client, $run, 'skipped', 'Nothing to review', $reason);

                return;
            }

            $knowledge = $knowledgeRetriever->forPullRequest($pullRequest, array_keys($context->files));

            $this->stage($run, ReviewStage::Mapping);
            $graph = $graphProvider->forReview($run, $context);
            $run->update([
                'graph_status' => $graph->status,
                'graph_risk_level' => $graph->context?->riskLevel,
                'graph_risk_score' => $graph->context?->riskScore,
                'graph_summary' => $graph->context?->summary() ?? ($graph->error !== null ? ['error' => $graph->error] : null),
            ]);

            $this->stage($run, ReviewStage::Analyzing);
            $analysis = $this->analyze($run, $analyzer, $context, $graph->context, $knowledge);
            $run->update([
                'tokens_input' => $analysis->inputTokens,
                'tokens_output' => $analysis->outputTokens,
                'risk_score' => $analysis->riskScore,
                'summary' => implode("\n\n", $analysis->summaries),
            ]);

            if ($this->isSuperseded($run)) {
                $this->finish($run, ReviewRunStatus::Superseded, 'Newer commits were pushed during the review.');
                $this->completeCheck($client, $run, 'cancelled', 'Superseded', 'A newer commit will be reviewed instead.');

                return;
            }

            $this->stage($run, ReviewStage::Validating);
            $findings = $this->storeFindings($run, $this->decide($run, $analysis, $verifier, $knowledge));
            $run->update([
                'tokens_input' => $run->tokens_input + $verifier->inputTokens,
                'tokens_output' => $run->tokens_output + $verifier->outputTokens,
                'findings_rejected' => $findings->where('status', FindingStatus::Rejected)->count(),
            ]);

            if ($run->is_dry_run) {
                $this->finish($run, ReviewRunStatus::Completed);

                return;
            }

            $this->reinforceMatchedLearnings($findings);

            if ($run->agent === null) {
                $this->markOutdatedFindings($run, $context, $findings, $signals);
            }

            $this->stage($run, ReviewStage::Publishing);
            $publisher->publish($run, $findings, (string) $run->summary);

            if ($this->movesReviewBaseline($run)) {
                $pullRequest->update(['last_reviewed_sha' => $run->head_sha]);
            }

            $this->finish($run, ReviewRunStatus::Completed);

            $published = $findings->where('status', FindingStatus::Published)->count();
            $this->completeCheck(
                $client,
                $run,
                $published === 0 ? 'success' : 'neutral',
                $published === 0 ? 'No issues found' : "{$published} ".str('issue')->plural($published).' found',
                "Reviewed {$run->files_reviewed} ".str('file')->plural($run->files_reviewed)." · risk score {$run->risk_score}/100.",
            );
        } catch (Throwable $exception) {
            $delay = TransientFailure::backoff($exception, $this->attempts());

            if ($delay !== null && $this->attempts() < self::MAX_TRANSIENT_ATTEMPTS) {
                $run->update(['status' => ReviewRunStatus::Queued, 'stage' => null, 'error' => null]);
                ReviewRunUpdated::dispatch($run);
                $this->release($delay);

                return;
            }

            report($exception);

            $this->finish($run, ReviewRunStatus::Failed, $exception->getMessage());
            $this->completeCheck($client, $run, 'neutral', 'Review failed', 'The review could not be completed. It can be retried from the dashboard.');
        }
    }

    /**
     * The general review plus every agent set to join automatic reviews whose files changed,
     * or only the requested agent for an agent run.
     */
    private function analyze(ReviewRun $run, ReviewAnalyzer $analyzer, ReviewContext $context, ?GraphContext $graph, ReviewKnowledge $knowledge): ReviewAnalysis
    {
        $pullRequest = $run->pullRequest;

        if ($run->agent !== null) {
            return $analyzer->analyze($pullRequest, $context, $graph, $run->agent, $knowledge);
        }

        $analysis = $analyzer->analyze($pullRequest, $context, $graph, knowledge: $knowledge);

        $agents = ReviewAgent::availableFor($pullRequest->repository)->where('run_on_auto_review', true);

        foreach ($agents as $agent) {
            $agentContext = $context->onlyFiles(fn (FilePatch $file): bool => $agent->matchesPath($file->path));

            if ($agentContext->files === []) {
                continue;
            }

            try {
                $analysis = $analysis->merge($analyzer->analyze($pullRequest, $agentContext, $graph, $agent, $knowledge));
            } catch (Throwable $exception) {
                // One failing agent must not sink the whole review.
                report($exception);
            }
        }

        return $analysis;
    }

    /**
     * Only live, general reviews move the commit that incremental reviews start from.
     */
    private function movesReviewBaseline(ReviewRun $run): bool
    {
        return ! $run->is_dry_run && $run->agent === null;
    }

    /**
     * Decide each finding's fate: duplicate of an earlier comment, below the risk gate,
     * rejected by the verifier, or published (with the verifier's adjusted assessment).
     *
     * @return list<array{finding: ProposedFinding, status: FindingStatus, verification: ?FindingVerification, note: ?string, learning_id: ?int}>
     */
    private function decide(ReviewRun $run, ReviewAnalysis $analysis, FindingVerifierService $verifier, ReviewKnowledge $knowledge): array
    {
        $previous = ReviewFinding::query()
            ->where('pull_request_id', $run->pull_request_id)
            ->where('review_run_id', '!=', $run->id)
            ->whereIn('status', [FindingStatus::Published, FindingStatus::Dismissed])
            ->whereHas('reviewRun', fn ($query) => $query->where('is_dry_run', false))
            ->get(['fingerprint', 'status']);

        $alreadyPublished = $previous->where('status', FindingStatus::Published)->pluck('fingerprint')->all();
        $dismissed = $previous->where('status', FindingStatus::Dismissed)->pluck('fingerprint')->all();

        $decisions = [];
        $candidates = [];

        foreach ($analysis->findings as $index => $finding) {
            $status = match (true) {
                in_array($finding->fingerprint(), $dismissed, true) => FindingStatus::Dismissed,
                in_array($finding->fingerprint(), $alreadyPublished, true) => FindingStatus::Duplicate,
                $finding->riskScore() >= $run->risk_threshold => FindingStatus::Published,
                default => FindingStatus::Suppressed,
            };

            $decisions[$index] = ['finding' => $finding, 'status' => $status, 'verification' => null, 'note' => null, 'learning_id' => null];

            if ($status === FindingStatus::Published) {
                $candidates[$index] = $finding;
            }
        }

        if ($candidates === [] || ! config('reviews.verifier.enabled')) {
            return array_values($decisions);
        }

        $verdicts = $verifier->verify($run, $candidates, $knowledge);

        foreach ($candidates as $index => $finding) {
            $verdict = $verdicts[$index] ?? null;

            if ($verdict === null) {
                $decisions[$index]['verification'] = FindingVerification::Skipped;

                continue;
            }

            $decisions[$index]['verification'] = $verdict->verification;
            $decisions[$index]['note'] = $verdict->reason !== '' ? $verdict->reason : null;

            if ($verdict->verification === FindingVerification::Rejected) {
                $decisions[$index]['status'] = FindingStatus::Rejected;
                $decisions[$index]['learning_id'] = $verdict->learningId;

                continue;
            }

            $assessed = $finding->withAssessment($verdict->severity ?? $finding->severity, $verdict->confidence ?? $finding->confidence);
            $decisions[$index]['finding'] = $assessed;
            $decisions[$index]['status'] = $assessed->riskScore() >= $run->risk_threshold ? FindingStatus::Published : FindingStatus::Suppressed;
        }

        return array_values($decisions);
    }

    /**
     * @param  list<array{finding: ProposedFinding, status: FindingStatus, verification: ?FindingVerification, note: ?string, learning_id: ?int}>  $decisions
     * @return Collection<int, ReviewFinding>
     */
    private function storeFindings(ReviewRun $run, array $decisions): Collection
    {
        return collect($decisions)->map(function (array $decision) use ($run): ReviewFinding {
            $finding = $decision['finding'];

            return $run->findings()->create([
                'pull_request_id' => $run->pull_request_id,
                'review_agent_id' => $finding->agentId,
                'fingerprint' => $finding->fingerprint(),
                'category' => $finding->category,
                'severity' => $finding->severity,
                'confidence' => $finding->confidence,
                'risk_score' => $finding->riskScore(),
                'file_path' => $finding->path,
                'start_line' => $finding->startLine,
                'end_line' => $finding->endLine,
                'title' => $finding->title,
                'body' => $finding->body,
                'suggestion' => $finding->suggestion,
                'code' => $finding->code,
                'graph_symbol' => $finding->graphSymbol,
                'graph_callers' => $finding->graphCallers,
                'status' => $decision['status'],
                'verification' => $decision['verification'],
                'verifier_note' => $decision['note'],
                'learning_id' => $decision['learning_id'],
            ]);
        });
    }

    /**
     * Learnings that just filtered out a finding are worth keeping around.
     *
     * @param  Collection<int, ReviewFinding>  $findings
     */
    private function reinforceMatchedLearnings(Collection $findings): void
    {
        $ids = $findings->pluck('learning_id')->filter()->unique()->values()->all();

        if ($ids !== []) {
            Learning::query()->whereKey($ids)->get()->each->reinforce();
        }
    }

    /**
     * Earlier published findings in re-reviewed files whose code is gone from the diff are outdated:
     * the author fixed them, by committing the suggestion or otherwise.
     *
     * @param  Collection<int, ReviewFinding>  $findings
     */
    private function markOutdatedFindings(ReviewRun $run, ReviewContext $context, Collection $findings, SignalRecorder $signals): void
    {
        $current = $findings->pluck('fingerprint')->all();

        ReviewFinding::query()
            ->where('pull_request_id', $run->pull_request_id)
            ->where('review_run_id', '!=', $run->id)
            ->where('status', FindingStatus::Published)
            ->whereHas('reviewRun', fn ($query) => $query->where('is_dry_run', false))
            ->whereIn('file_path', array_keys($context->files))
            ->whereNotIn('fingerprint', $current)
            ->get()
            ->each(function (ReviewFinding $previous) use ($context, $signals): void {
                $file = $context->file($previous->file_path);
                $stillThere = $file !== null
                    && $previous->code !== null
                    && $previous->code !== ''
                    && str_contains(implode("\n", $file->commentableLines), $previous->code);

                if (! $stillThere) {
                    $previous->update(['status' => FindingStatus::Outdated]);
                    $signals->record($previous, $this->suggestionWasApplied($previous, $file) ? SignalType::Applied : SignalType::Fixed);
                }
            });
    }

    /**
     * Whether the finding's suggested code now appears among the file's new lines.
     */
    private function suggestionWasApplied(ReviewFinding $finding, ?FilePatch $file): bool
    {
        if ($finding->suggestion === null || $file === null) {
            return false;
        }

        $normalize = fn (string $code): string => (string) preg_replace('/\s+/', '', $code);

        return str_contains($normalize(implode("\n", $file->commentableLines)), $normalize($finding->suggestion));
    }

    private function isSuperseded(ReviewRun $run): bool
    {
        return $run->pullRequest->fresh()?->head_sha !== $run->head_sha;
    }

    private function stage(ReviewRun $run, ReviewStage $stage): void
    {
        $run->update(['stage' => $stage]);
        ReviewRunUpdated::dispatch($run);
    }

    private function finish(ReviewRun $run, ReviewRunStatus $status, ?string $error = null): void
    {
        $run->update([
            'status' => $status,
            'stage' => $status === ReviewRunStatus::Failed ? $run->stage : null,
            'error' => $status === ReviewRunStatus::Failed ? $error : null,
            'summary' => $status === ReviewRunStatus::Completed || $status === ReviewRunStatus::Failed ? $run->summary : ($run->summary ?? $error),
            'completed_at' => now(),
        ]);

        ReviewRunUpdated::dispatch($run);
    }

    /**
     * @param  'success'|'neutral'|'failure'|'cancelled'|'skipped'  $conclusion
     */
    private function completeCheck(PullRequestClient $client, ReviewRun $run, string $conclusion, string $title, string $summary): void
    {
        if ($run->github_check_run_id === null) {
            return;
        }

        try {
            $client->completeCheckRun($run->pullRequest, $run->github_check_run_id, $conclusion, $title, $summary);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
