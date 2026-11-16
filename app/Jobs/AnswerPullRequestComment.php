<?php

namespace App\Jobs;

use App\Ai\Agents\PullRequestAssistant;
use App\Enums\AiFeature;
use App\Enums\ConversationRole;
use App\Enums\ConversationStatus;
use App\Events\ConversationUpdated;
use App\Models\ConversationMessage;
use App\Services\Conversation\AssistantPromptBuilder;
use App\Services\Conversation\CommentResponder;
use App\Services\Usage\UsageMeter;
use App\Support\TransientFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Answers a question asked to the bot on GitHub and posts the answer where it was asked.
 */
class AnswerPullRequestComment implements ShouldQueue
{
    use Queueable;

    /**
     * Rate limits and overloads are retried; any other failure is reported in the thread once, so nobody gets two answers.
     */
    public int $tries = 4;

    public int $timeout = 300;

    public function __construct(public ConversationMessage $question)
    {
        $this->onQueue('conversations');
    }

    public function handle(AssistantPromptBuilder $prompts, CommentResponder $responder, UsageMeter $usage): void
    {
        $question = $this->question->fresh(['pullRequest.repository.organization', 'pullRequest.repository.installation', 'finding']);

        if ($question === null || $question->status !== ConversationStatus::Pending) {
            return;
        }

        $pullRequest = $question->pullRequest;
        $handle = $pullRequest->repository->organization->bot_handle;

        $organization = $pullRequest->repository->organization;

        if ($usage->isOverBudget($organization)) {
            $responder->reply($pullRequest, $question->thread_comment_id, "{$organization->name} reached its monthly AI budget, so I can't answer until next month or until an owner raises it.", $question->isInThread() ? null : $question->author_login);
            $question->update(['status' => ConversationStatus::Failed]);
            ConversationUpdated::dispatch($pullRequest);

            return;
        }

        try {
            $response = PullRequestAssistant::make(handle: $handle)->prompt(
                $prompts->build($question),
                provider: config('reviews.provider'),
                model: config('reviews.conversation.model'),
                timeout: (int) config('reviews.timeout'),
            );

            $answer = trim($response->text);
            $tokens = [$response->usage->inputTokens, $response->usage->outputTokens];
            $usage->record($organization, AiFeature::Conversation, config('reviews.provider'), config('reviews.conversation.model'), $response->usage, pullRequest: $pullRequest);
        } catch (Throwable $exception) {
            $delay = TransientFailure::backoff($exception, $this->attempts());

            if ($delay !== null && $this->attempts() < $this->tries) {
                $this->release($delay);

                return;
            }

            report($exception);

            $answer = null;
            $tokens = [0, 0];
        }

        $status = $answer !== null && $answer !== '' ? ConversationStatus::Answered : ConversationStatus::Failed;
        $body = $status === ConversationStatus::Answered
            ? $answer
            : "Sorry, I couldn't answer that right now. Please try again in a few minutes.";

        $comment = $responder->reply($pullRequest, $question->thread_comment_id, (string) $body, $question->isInThread() ? null : $question->author_login);

        $pullRequest->conversationMessages()->create([
            'review_finding_id' => $question->review_finding_id,
            'in_reply_to_id' => $question->id,
            'thread_comment_id' => $question->thread_comment_id,
            'github_comment_id' => $comment['id'] ?? null,
            'role' => ConversationRole::Bot,
            'author_login' => $handle,
            'body' => (string) $body,
            'status' => $status,
            'tokens_input' => $tokens[0],
            'tokens_output' => $tokens[1],
        ]);

        $question->update(['status' => $status]);

        ConversationUpdated::dispatch($pullRequest);
    }

    public function failed(?Throwable $exception): void
    {
        $this->question->update(['status' => ConversationStatus::Failed]);
    }
}
