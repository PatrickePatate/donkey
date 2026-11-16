<?php

namespace App\Actions;

use App\Enums\ReviewRunStatus;
use App\Enums\ReviewTrigger;
use App\Events\ReviewRunUpdated;
use App\Jobs\ReviewPullRequest;
use App\Models\PullRequest;
use App\Models\ReviewRun;

/**
 * Queues a review run once per (pull request, commit, trigger source).
 */
class StartReview
{
    /**
     * @param  string  $source  Distinguishes requests for the same commit, e.g. the comment id.
     */
    public function handle(
        PullRequest $pullRequest,
        ReviewTrigger $trigger,
        ?string $triggeredBy = null,
        bool $full = false,
        string $source = '',
    ): ?ReviewRun {
        $key = implode(':', [$pullRequest->id, $pullRequest->head_sha, $trigger->value, $full ? 'full' : 'incremental', $source]);

        $run = $pullRequest->reviewRuns()->createOrFirst(['idempotency_key' => $key], [
            'head_sha' => $pullRequest->head_sha,
            'trigger' => $trigger,
            'triggered_by' => $triggeredBy,
            'is_full' => $full,
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
