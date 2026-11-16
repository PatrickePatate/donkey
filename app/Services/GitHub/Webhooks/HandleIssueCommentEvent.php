<?php

namespace App\Services\GitHub\Webhooks;

use App\Actions\SyncPullRequest;
use App\Enums\WebhookEventStatus;
use App\Models\GithubInstallation;
use App\Models\WebhookEvent;
use App\Services\Conversation\BotCommandHandler;
use App\Services\Conversation\IncomingComment;
use App\Services\GitHub\PullRequestClient;
use App\Services\Review\ReviewCommand;

/**
 * Handles comments addressed to the bot in the pull request conversation.
 */
class HandleIssueCommentEvent
{
    public function __construct(
        private SyncPullRequest $syncPullRequest,
        private PullRequestClient $client,
        private BotCommandHandler $commands,
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

        if ($command === null) {
            return WebhookEventStatus::Ignored;
        }

        $pullRequest = $repository->pullRequests()->firstWhere('number', $payload['issue']['number'])
            ?? $this->syncPullRequest->handle($repository, $this->client->get($repository, (int) $payload['issue']['number']));

        $comment = new IncomingComment(
            pullRequest: $pullRequest->setRelation('repository', $repository),
            commentId: (int) $payload['comment']['id'],
            body: (string) $payload['comment']['body'],
            authorLogin: (string) ($payload['comment']['user']['login'] ?? $payload['sender']['login'] ?? 'unknown'),
            authorAssociation: (string) ($payload['comment']['author_association'] ?? ''),
        );

        if (! $this->commands->isAllowed($command, $comment)) {
            return WebhookEventStatus::Ignored;
        }

        $this->commands->handle($command, $comment);

        return WebhookEventStatus::Processed;
    }
}
