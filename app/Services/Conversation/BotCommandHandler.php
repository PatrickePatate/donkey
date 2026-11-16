<?php

namespace App\Services\Conversation;

use App\Actions\StartReview;
use App\Enums\BotCommandType;
use App\Enums\ConversationRole;
use App\Enums\ConversationStatus;
use App\Enums\FindingStatus;
use App\Enums\ReviewTrigger;
use App\Events\ConversationUpdated;
use App\Jobs\AnswerPullRequestComment;
use App\Models\ReviewAgent;
use App\Services\Review\ReviewCommand;

/**
 * Carries out a command written to the bot in a pull request comment.
 */
class BotCommandHandler
{
    /**
     * Associations allowed to drive the bot. Read-only commands are also open to past contributors.
     */
    private const WRITE_ASSOCIATIONS = ['OWNER', 'MEMBER', 'COLLABORATOR'];

    private const READ_ASSOCIATIONS = [...self::WRITE_ASSOCIATIONS, 'CONTRIBUTOR'];

    public function __construct(private StartReview $startReview, private CommentResponder $responder) {}

    /**
     * Whether the comment's author may run this command at all; unauthorized comments are silently ignored.
     */
    public function isAllowed(ReviewCommand $command, IncomingComment $comment): bool
    {
        $allowed = $command->type->requiresWriteAccess() ? self::WRITE_ASSOCIATIONS : self::READ_ASSOCIATIONS;

        return in_array($comment->authorAssociation, $allowed, true);
    }

    public function handle(ReviewCommand $command, IncomingComment $comment): void
    {
        match ($command->type) {
            BotCommandType::Review, BotCommandType::FullReview => $this->review($command, $comment),
            BotCommandType::RunAgent => $this->runAgent($command->arguments, $comment),
            BotCommandType::ListAgents => $this->responder->replyTo($comment, $this->agentList($comment)),
            BotCommandType::Ignore => $this->ignore($comment),
            BotCommandType::Pause => $this->setPaused($comment, true),
            BotCommandType::Resume => $this->setPaused($comment, false),
            BotCommandType::Help => $this->responder->replyTo($comment, $this->help($comment)),
            BotCommandType::Ask => $this->ask($command->arguments, $comment),
        };
    }

    private function review(ReviewCommand $command, IncomingComment $comment): void
    {
        if (! $comment->pullRequest->isOpen()) {
            $this->responder->replyTo($comment, 'This pull request is closed, so there is nothing to review.');

            return;
        }

        $this->responder->react($comment, 'eyes');

        $this->startReview->handle(
            $comment->pullRequest,
            ReviewTrigger::Comment,
            $comment->authorLogin,
            $command->isFullReview(),
            source: (string) $comment->commentId,
        );
    }

    private function runAgent(string $slug, IncomingComment $comment): void
    {
        $agent = ReviewAgent::findForRepository($comment->pullRequest->repository, $slug);

        if ($agent === null) {
            $this->responder->replyTo($comment, "I don't know an agent called `{$slug}` here.\n\n".$this->agentList($comment));

            return;
        }

        if (! $comment->pullRequest->isOpen()) {
            $this->responder->replyTo($comment, 'This pull request is closed, so there is nothing to review.');

            return;
        }

        $this->responder->react($comment, 'rocket');

        $this->startReview->handle(
            $comment->pullRequest,
            ReviewTrigger::Comment,
            $comment->authorLogin,
            source: (string) $comment->commentId,
            agent: $agent,
        );
    }

    private function ignore(IncomingComment $comment): void
    {
        $finding = $comment->finding;
        $handle = $this->botHandle($comment);

        if ($finding === null) {
            $this->responder->replyTo($comment, "Reply `@{$handle} ignore` in the thread of one of my review comments to dismiss that finding.");

            return;
        }

        if ($finding->status !== FindingStatus::Dismissed) {
            $finding->update(['status' => FindingStatus::Dismissed, 'dismissed_by' => $comment->authorLogin]);
        }

        $this->responder->react($comment, '+1');
        $this->responder->replyTo($comment, "Dismissed. I won't raise this again on this pull request.");
        ConversationUpdated::dispatch($comment->pullRequest);
    }

    private function setPaused(IncomingComment $comment, bool $paused): void
    {
        $comment->pullRequest->update(['auto_review_paused' => $paused]);
        $handle = $this->botHandle($comment);

        $this->responder->replyTo($comment, $paused
            ? "Automatic reviews are paused on this pull request. Comment `@{$handle} review` to request one anyway, or `@{$handle} resume` to turn them back on."
            : 'Automatic reviews are back on for this pull request.');

        ConversationUpdated::dispatch($comment->pullRequest);
    }

    /**
     * Store the question and answer it in the background; a redelivered comment is answered once.
     */
    private function ask(string $question, IncomingComment $comment): void
    {
        if (trim($question) === '') {
            $this->responder->replyTo($comment, $this->help($comment));

            return;
        }

        $message = $comment->pullRequest->conversationMessages()->createOrFirst(
            ['github_comment_id' => $comment->commentId],
            [
                'review_finding_id' => $comment->finding?->id,
                'thread_comment_id' => $comment->threadCommentId,
                'role' => ConversationRole::User,
                'author_login' => $comment->authorLogin,
                'body' => $question,
                'status' => ConversationStatus::Pending,
            ],
        );

        if (! $message->wasRecentlyCreated) {
            return;
        }

        $this->responder->react($comment, 'eyes');
        ConversationUpdated::dispatch($comment->pullRequest);
        AnswerPullRequestComment::dispatch($message);
    }

    private function agentList(IncomingComment $comment): string
    {
        $agents = ReviewAgent::availableFor($comment->pullRequest->repository);
        $handle = $this->botHandle($comment);

        if ($agents->isEmpty()) {
            return 'No review agents are set up for this repository yet. Admins can create them in the Donkey dashboard.';
        }

        $lines = ['Agents available on this repository:', ''];

        foreach ($agents as $agent) {
            $lines[] = "- `@{$handle} run {$agent->slug}` — **{$agent->name}**"
                .(filled($agent->description) ? ": {$agent->description}" : '')
                .($agent->run_on_auto_review ? ' _(also runs on automatic reviews)_' : '');
        }

        return implode("\n", $lines);
    }

    private function help(IncomingComment $comment): string
    {
        $handle = $this->botHandle($comment);

        return implode("\n", [
            'Here is what I can do:',
            '',
            "- `@{$handle} review` — review the changes since my last review",
            "- `@{$handle} full review` — review the whole pull request again",
            "- `@{$handle} run <agent>` — run one specialist agent; `@{$handle} agents` lists them",
            "- `@{$handle} ignore` — in the thread of one of my comments, dismiss that finding",
            "- `@{$handle} pause` / `@{$handle} resume` — stop or restart automatic reviews on this pull request",
            "- `@{$handle} <question>` — ask me anything about this pull request or, in a thread, about that finding",
        ]);
    }

    private function botHandle(IncomingComment $comment): string
    {
        return $comment->pullRequest->repository->organization->bot_handle;
    }
}
