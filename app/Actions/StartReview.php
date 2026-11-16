<?php

namespace App\Actions;

use App\Enums\ReviewRunStatus;
use App\Enums\ReviewTrigger;
use App\Events\ReviewRunUpdated;
use App\Jobs\ReviewPullRequest;
use App\Models\PullRequest;
use App\Models\ReviewAgent;
use App\Models\ReviewRun;

/**
 * Queues a review run once per (pull request, commit, trigger source).
 */
class StartReview
{
    /**
     * @param  string  $source  Distinguishes requests for the same commit, e.g. the comment id.
     * @param  ?ReviewAgent  $agent  Run only this specialist agent, over the whole pull request.
     * @param  bool  $dryRun  Review without posting anything to GitHub, e.g. to try an agent from the dashboard.
     */
    public function handle(
        PullRequest $pullRequest,
        ReviewTrigger $trigger,
        ?string $triggeredBy = null,
        bool $full = false,
        string $source = '',
        ?ReviewAgent $agent = null,
        bool $dryRun = false,
    ): ?ReviewRun {
        // An agent has not seen this pull request before, so it always reviews all of it.
        $full = $full || $agent !== null;

        $key = implode(':', [
            $pullRequest->id,
            $pullRequest->head_sha,
            $trigger->value,
            $full ? 'full' : 'incremental',
            $agent !== null ? "agent-{$agent->id}" : 'core',
            $dryRun ? 'dry' : 'live',
            $source,
        ]);

        $run = $pullRequest->reviewRuns()->createOrFirst(['idempotency_key' => $key], [
            'head_sha' => $pullRequest->head_sha,
            'trigger' => $trigger,
            'review_agent_id' => $agent?->id,
            'triggered_by' => $triggeredBy,
            'is_full' => $full,
            'is_dry_run' => $dryRun,
            'status' => ReviewRunStatus::Queued,
        ]);

        if (! $run->wasRecentlyCreated) {
            return null;
        }

        ReviewRunUpdated::dispatch($run);
        ReviewPullRequest::dispatch($run);

        return $run;
    }
}
