<?php

namespace App\Services\GitHub\Webhooks;

use App\Actions\StartReview;
use App\Actions\SyncPullRequest;
use App\Enums\ReviewTrigger;
use App\Enums\WebhookEventStatus;
use App\Jobs\BuildRepositoryGraph;
use App\Jobs\CollectFindingFeedback;
use App\Jobs\GenerateRepositoryDocumentation;
use App\Jobs\ScanRepositoryDependencies;
use App\Models\GithubInstallation;
use App\Models\WebhookEvent;

/**
 * Keeps pull requests in sync and starts automatic reviews when new code is pushed.
 */
class HandlePullRequestEvent
{
    /**
     * Actions that bring new code (or make the PR reviewable) and should trigger an auto-review.
     */
    private const REVIEWABLE_ACTIONS = ['opened', 'reopened', 'synchronize', 'ready_for_review'];

    public function __construct(private SyncPullRequest $syncPullRequest, private StartReview $startReview)
    {
    }

    public function handle(?GithubInstallation $installation, WebhookEvent $event): WebhookEventStatus
    {
        $payload = $event->payload;
        $repository = $installation?->repositories()->with('organization')->firstWhere('github_id', $payload['repository']['id'] ?? 0);

        if ($repository === null || !isset($payload['pull_request'])) {
            return WebhookEventStatus::Ignored;
        }

        $pullRequest = $this->syncPullRequest->handle($repository, $payload['pull_request']);

        if (
            in_array($event->action, self::REVIEWABLE_ACTIONS, true)
            && $pullRequest->isOpen()
            && !$pullRequest->is_draft
            && !$pullRequest->auto_review_paused
            && $repository->autoReviewEnabled()
        ) {
            $this->startReview->handle($pullRequest, ReviewTrigger::Auto, $payload['sender']['login'] ?? null);
        }

        if ($event->action === 'closed') {
            CollectFindingFeedback::dispatch($pullRequest)->delay(now()->addMinute());
        }

        $mergeCommit = $payload['pull_request']['merge_commit_sha'] ?? null;

        if ($event->action === 'closed' && $pullRequest->state === 'merged' && $pullRequest->base_ref === $repository->default_branch && is_string($mergeCommit)) {
            BuildRepositoryGraph::dispatch($repository, $mergeCommit);
            ScanRepositoryDependencies::dispatch($repository);

            if ($repository->auto_docs_enabled) {
                GenerateRepositoryDocumentation::dispatchFor($repository);
            }
        }

        return WebhookEventStatus::Processed;
    }
}
