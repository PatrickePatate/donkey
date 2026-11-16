<?php

namespace App\Services\Conversation;

use App\Models\PullRequest;
use App\Models\ReviewFinding;

/**
 * A GitHub comment addressed to the bot, from the pull request conversation or an inline review thread.
 */
final class IncomingComment
{
    /**
     * @param  ?int  $threadCommentId  Top-level comment of the inline review thread, null in the PR conversation.
     * @param  ?ReviewFinding  $finding  The finding whose comment started the thread, if any.
     */
    public function __construct(
        public readonly PullRequest $pullRequest,
        public readonly int $commentId,
        public readonly string $body,
        public readonly string $authorLogin,
        public readonly string $authorAssociation,
        public readonly ?int $threadCommentId = null,
        public readonly ?ReviewFinding $finding = null,
    ) {}

    public function isInThread(): bool
    {
        return $this->threadCommentId !== null;
    }
}
