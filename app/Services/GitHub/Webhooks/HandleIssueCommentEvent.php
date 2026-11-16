<?php

namespace App\Services\GitHub\Webhooks;

use App\Actions\StartReview;
use App\Actions\SyncPullRequest;
use App\Enums\ReviewTrigger;
use App\Enums\WebhookEventStatus;
use App\Models\GithubInstallation;
use App\Models\WebhookEvent;
use App\Services\GitHub\PullRequestClient;
use App\Services\Review\ReviewCommand;

/**
 * Handles "@bot review" style commands written in pull request conversations.
 */
class HandleIssueCommentEvent
{
    /**
     * Only people with write-level relationships to the repository may drive the bot.
     */
    private const TRUSTED_ASSOCIATIONS = ['OWNER', 'MEMBER', 'COLLABORATOR'];

    public function __construct(
        private SyncPullRequest $syncPullRequest,
        private StartReview $startReview,
        private PullRequestClient $client,
    ) {}

    public function handle(?GithubInstallation $installation, WebhookEvent $event): WebhookEventStatus
    {
        $payload = $event->payload;

        if (
            $event->action !== 'created'
            || ! isset($payload['issue']['pull_request'])
            || ($payload['sender']['type'] ?? null) === 'Bot'
        ) {
            return WebhookEventStatus::Ignored;
        }

        $repository = $installation?->repositories()->with('organization')->firstWhere('github_id', $payload['repository']['id'] ?? 0);

        if ($repository === null) {
            return WebhookEventStatus::Ignored;
        }

        $command = ReviewCommand::parse((string) ($payload['comment']['body'] ?? ''), $repository->organization->bot_handle);

        if ($command === null || ! in_array($payload['comment']['author_association'] ?? '', self::TRUSTED_ASSOCIATIONS, true)) {
            return WebhookEventStatus::Ignored;
        }

        $pullRequest = $repository->pullRequests()->firstWhere('number', $payload['issue']['number'])
            ?? $this->syncPullRequest->handle($repository, $this->client->get($repository, (int) $payload['issue']['number']));

        $commentId = (int) $payload['comment']['id'];

        if (! $command->isReview()) {
            $handle = $repository->organization->bot_handle;
            $this->client->createIssueComment($pullRequest, implode("\n", [
                "I didn't understand that command. Available commands:",
                '',
                "- `@{$handle} review` — review the changes since my last review",
                "- `@{$handle} full review` — review the whole pull request again",
            ]));

            return WebhookEventStatus::Processed;
        }

        $this->client->reactToComment($pullRequest, $commentId, 'eyes');

        $this->startReview->handle(
            $pullRequest,
            ReviewTrigger::Comment,
            $payload['sender']['login'] ?? null,
            $command->isFullReview(),
            source: (string) $commentId,
        );

        return WebhookEventStatus::Processed;
    }
}
