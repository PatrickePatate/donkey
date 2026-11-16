<?php

namespace App\Services\Conversation;

use App\Models\PullRequest;
use App\Services\GitHub\PullRequestClient;
use Throwable;

/**
 * Answers where the comment was written: in its inline thread, or in the pull request conversation.
 */
class CommentResponder
{
    public function __construct(private PullRequestClient $client) {}

    /**
     * @return array<string, mixed> The created GitHub comment.
     */
    public function reply(PullRequest $pullRequest, ?int $threadCommentId, string $body, ?string $quoting = null): array
    {
        if ($threadCommentId !== null) {
            return $this->client->replyToReviewComment($pullRequest, $threadCommentId, $body);
        }

        return $this->client->createIssueComment($pullRequest, $quoting !== null ? "@{$quoting} {$body}" : $body);
    }

    public function replyTo(IncomingComment $comment, string $body): void
    {
        $this->reply($comment->pullRequest, $comment->threadCommentId, $body, $comment->isInThread() ? null : $comment->authorLogin);
    }

    /**
     * Acknowledge a comment with a reaction; best effort, never fails the command.
     */
    public function react(IncomingComment $comment, string $content): void
    {
        try {
            $comment->isInThread()
                ? $this->client->reactToReviewComment($comment->pullRequest, $comment->commentId, $content)
                : $this->client->reactToComment($comment->pullRequest, $comment->commentId, $content);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
