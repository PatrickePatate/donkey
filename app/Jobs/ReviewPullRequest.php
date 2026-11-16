<?php

namespace App\Jobs;

use App\Enums\FindingStatus;
use App\Enums\ReviewRunStatus;
use App\Enums\ReviewStage;
use App\Events\ReviewRunUpdated;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
use App\Services\GitHub\PullRequestClient;
use App\Services\Review\ReviewAnalysis;
use App\Services\Review\ReviewAnalyzer;
use App\Services\Review\ReviewContext;
use App\Services\Review\ReviewContextBuilder;
use App\Services\Review\ReviewPublisher;
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
        ReviewAnalyzer $analyzer,
        ReviewPublisher $publisher,
        PullRequestClient $client,
    ): void {
        $run = $this->run->fresh(['pullRequest.repository.organization', 'pullRequest.repository.installation']);

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

        $run->update([
            'status' => ReviewRunStatus::Running,
            'started_at' => now(),
            'provider' => config('reviews.provider'),
            'model' => config('reviews.model'),
            'risk_threshold' => $pullRequest->repository->effectiveRiskThreshold(),
        ]);

        $checkRunId = $client->createCheckRun($pullRequest, config('reviews.check_run_name'), $run->head_sha, route('pull-requests.show', $pullRequest));
        $run->update(['github_check_run_id' => $checkRunId]);

        try {
            $this->stage($run, ReviewStage::Collecting);
            $context = $contextBuilder->build($run);
            $run->update(['base_sha' => $context->baseSha, 'files_reviewed' => count($context->files)]);

            if ($context->files === []) {
                $pullRequest->update(['last_reviewed_sha' => $run->head_sha]);
                $this->finish($run, ReviewRunStatus::Skipped, 'No reviewable changes.');
                $this->completeCheck($client, $run, 'skipped', 'Nothing to review', 'No reviewable changes in this push.');

                return;
            }

            $this->stage($run, ReviewStage::Analyzing);
            $analysis = $analyzer->analyze($pullRequest, $context);
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
            $findings = $this->storeFindings($run, $analysis);
            $this->markOutdatedFindings($run, $context, $findings);

            $this->stage($run, ReviewStage::Publishing);
            $publisher->publish($run, $findings, (string) $run->summary);

            $pullRequest->update(['last_reviewed_sha' => $run->head_sha]);
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
            report($exception);

            $this->finish($run, ReviewRunStatus::Failed, $exception->getMessage());
            $this->completeCheck($client, $run, 'neutral', 'Review failed', 'The review could not be completed. It can be retried from the dashboard.');
        }
    }

    /**
     * @return Collection<int, ReviewFinding>
     */
    private function storeFindings(ReviewRun $run, ReviewAnalysis $analysis): Collection
    {
        $alreadyPublished = ReviewFinding::query()
            ->where('pull_request_id', $run->pull_request_id)
            ->where('review_run_id', '!=', $run->id)
            ->where('status', FindingStatus::Published)
            ->pluck('fingerprint')
            ->all();

        return collect($analysis->findings)->map(function ($finding) use ($run, $alreadyPublished): ReviewFinding {
            $status = match (true) {
                in_array($finding->fingerprint(), $alreadyPublished, true) => FindingStatus::Duplicate,
                $finding->riskScore() >= $run->risk_threshold => FindingStatus::Published,
                default => FindingStatus::Suppressed,
            };

            return $run->findings()->create([
                'pull_request_id' => $run->pull_request_id,
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
                'status' => $status,
            ]);
        });
    }

    /**
     * Earlier published findings in re-reviewed files whose code is gone from the diff are outdated.
     *
     * @param  Collection<int, ReviewFinding>  $findings
     */
    private function markOutdatedFindings(ReviewRun $run, ReviewContext $context, Collection $findings): void
    {
        $current = $findings->pluck('fingerprint')->all();

        ReviewFinding::query()
            ->where('pull_request_id', $run->pull_request_id)
            ->where('review_run_id', '!=', $run->id)
            ->where('status', FindingStatus::Published)
            ->whereIn('file_path', array_keys($context->files))
            ->whereNotIn('fingerprint', $current)
            ->get()
            ->each(function (ReviewFinding $previous) use ($context): void {
                $file = $context->file($previous->file_path);
                $stillThere = $file !== null
                    && $previous->code !== null
                    && $previous->code !== ''
                    && str_contains(implode("\n", $file->commentableLines), $previous->code);

                if (! $stillThere) {
                    $previous->update(['status' => FindingStatus::Outdated]);
                }
            });
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
