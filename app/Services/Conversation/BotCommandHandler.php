<?php

namespace App\Services\Conversation;

use App\Actions\StartReview;
use App\Enums\BotCommandType;
use App\Enums\ConversationRole;
use App\Enums\ConversationStatus;
use App\Enums\FindingStatus;
use App\Enums\ReviewTrigger;
use App\Enums\SignalType;
use App\Events\ConversationUpdated;
use App\Jobs\AnswerPullRequestComment;
use App\Models\RepositoryMemory;
use App\Models\ReviewAgent;
use App\Services\GitHub\PullRequestClient;
use App\Services\Knowledge\SignalRecorder;
use App\Services\Review\ReviewCommand;
use Illuminate\Support\Str;

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

    public function __construct(
        private StartReview $startReview,
        private CommentResponder $responder,
        private SignalRecorder $signals,
        private PullRequestClient $client,
    ) {
    }

    /**
     * Whether the comment's author may run this command at all; the payload's association is checked first, then the author's real repository permission
     * (private org members show up as CONTRIBUTOR on review comments); unauthorized comments get a thumbs-down reaction and are otherwise ignored.
     */
    public function isAllowed(ReviewCommand $command, IncomingComment $comment): bool
    {
        $allowed = $command->type->requiresWriteAccess() ? self::WRITE_ASSOCIATIONS : self::READ_ASSOCIATIONS;

        if (in_array($comment->authorAssociation, $allowed, true) || $this->client->hasWriteAccess($comment->pullRequest, $comment->authorLogin)) {
            return true;
        }

        // A thumbs-down tells the author the command was seen but refused, instead of silence.
        $this->responder->react($comment, '-1');

        return false;
    }

    public function handle(ReviewCommand $command, IncomingComment $comment): void
    {
        match ($command->type) {
            BotCommandType::Review, BotCommandType::FullReview => $this->review($command, $comment),
            BotCommandType::RunAgent => $this->runAgent($command->arguments, $comment),
            BotCommandType::ListAgents => $this->responder->replyTo($comment, $this->agentList($comment)),
            BotCommandType::Ignore => $this->ignore($comment, $command->arguments),
            BotCommandType::Remember => $this->remember($comment, $command->arguments),
            BotCommandType::Pause => $this->setPaused($comment, true),
            BotCommandType::Resume => $this->setPaused($comment, false),
            BotCommandType::Help => $this->responder->replyTo($comment, $this->help($comment)),
            BotCommandType::Ask => $this->ask($command->arguments, $comment),
        };
    }

    private function review(ReviewCommand $command, IncomingComment $comment): void
    {
        if (!$comment->pullRequest->isOpen()) {
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

        if (!$comment->pullRequest->isOpen()) {
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

    /**
     * @param  string  $reason  Why the finding is wrong, if the author said; it helps propose a learning.
     */
    private function ignore(IncomingComment $comment, string $reason): void
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

        if ($reason !== '') {
            $comment->pullRequest->conversationMessages()->createOrFirst(
                ['github_comment_id' => $comment->commentId],
                [
                    'review_finding_id' => $finding->id,
                    'thread_comment_id' => $comment->threadCommentId,
                    'role' => ConversationRole::User,
                    'author_login' => $comment->authorLogin,
                    'body' => "Dismissed: {$reason}",
                    'status' => ConversationStatus::Answered,
                ],
            );
        }

        $this->signals->record($finding, SignalType::Dismissed, $comment->authorLogin);

        $this->responder->react($comment, '+1');
        $this->responder->replyTo($comment, "Dismissed. I won't raise this again on this pull request, and I'll use your feedback to suggest a learning for future reviews.");
        ConversationUpdated::dispatch($comment->pullRequest);
    }

    /**
     * Save a repository memory. In a finding's thread, it is tied to that finding's file.
     */
    private function remember(IncomingComment $comment, string $text): void
    {
        $memory = $comment->pullRequest->repository->memories()->createOrFirst(
            ['github_comment_id' => $comment->commentId],
            [
                'author_login' => $comment->authorLogin,
                'body' => Str::limit($text, 2000),
                'path_glob' => $comment->finding?->file_path,
                'source' => RepositoryMemory::SOURCE_GITHUB,
            ],
        );

        if (!$memory->wasRecentlyCreated) {
            return;
        }

        $this->responder->react($comment, '+1');
        $this->responder->replyTo($comment, 'Noted. I will keep this in mind when reviewing '
            .($memory->path_glob !== null ? "`{$memory->path_glob}`" : $comment->pullRequest->repository->full_name)
            .'. Admins can edit or remove it from the Knowledge page in Donkey.');
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

        if (!$message->wasRecentlyCreated) {
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
            "- `@{$handle} ignore: <why>` — in the thread of one of my comments, dismiss that finding",
            "- `@{$handle} remember <fact>` — teach me something about this repository for future reviews",
            "- `@{$handle} pause` / `@{$handle} resume` — stop or restart automatic reviews on this pull request",
            "- `@{$handle} <question>` — ask me anything about this pull request or, in a thread, about that finding",
        ]);
    }

    private function botHandle(IncomingComment $comment): string
    {
        return $comment->pullRequest->repository->organization->bot_handle;
    }
}
