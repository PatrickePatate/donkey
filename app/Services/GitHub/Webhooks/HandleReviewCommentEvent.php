<?php

namespace App\Services\GitHub\Webhooks;

use App\Actions\SyncPullRequest;
use App\Enums\WebhookEventStatus;
use App\Models\GithubInstallation;
use App\Models\ReviewFinding;
use App\Models\WebhookEvent;
use App\Services\Conversation\BotCommandHandler;
use App\Services\Conversation\IncomingComment;
use App\Services\Review\ReviewCommand;

/**
 * Handles comments addressed to the bot in inline review threads, e.g. a question about one of its findings.
 */
class HandleReviewCommentEvent
{
    public function __construct(private SyncPullRequest $syncPullRequest, private BotCommandHandler $commands) {}

    public function handle(?GithubInstallation $installation, WebhookEvent $event): WebhookEventStatus
    {
        $payload = $event->payload;

        if (
            $event->action !== 'created'
            || ! isset($payload['pull_request'], $payload['comment']['id'])
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

        $pullRequest = $this->syncPullRequest->handle($repository, $payload['pull_request'])->setRelation('repository', $repository);
        $threadCommentId = (int) ($payload['comment']['in_reply_to_id'] ?? $payload['comment']['id']);

        $comment = new IncomingComment(
            pullRequest: $pullRequest,
            commentId: (int) $payload['comment']['id'],
            body: (string) $payload['comment']['body'],
            authorLogin: (string) ($payload['comment']['user']['login'] ?? $payload['sender']['login'] ?? 'unknown'),
            authorAssociation: (string) ($payload['comment']['author_association'] ?? ''),
            threadCommentId: $threadCommentId,
            finding: ReviewFinding::query()
                ->where('pull_request_id', $pullRequest->id)
                ->where('github_comment_id', $threadCommentId)
                ->latest('id')
                ->first(),
        );

        if (! $this->commands->isAllowed($command, $comment)) {
            return WebhookEventStatus::Ignored;
        }

        $this->commands->handle($command, $comment);

        return WebhookEventStatus::Processed;
    }
}
