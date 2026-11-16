<?php

namespace App\Models;

use App\Enums\ConversationRole;
use App\Enums\ConversationStatus;
use Database\Factories\ConversationMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message of a conversation with the bot: a question on GitHub or the bot's answer.
 *
 * Messages in an inline review thread share the thread's root comment id; messages in the
 * pull request conversation have no thread.
 *
 * @property int $id
 * @property int $pull_request_id
 * @property ?int $review_finding_id
 * @property ?int $in_reply_to_id
 * @property ?int $thread_comment_id
 * @property ?int $github_comment_id
 * @property ConversationRole $role
 * @property string $author_login
 * @property string $body
 * @property ConversationStatus $status
 * @property int $tokens_input
 * @property int $tokens_output
 * @property Carbon $created_at
 * @property-read PullRequest $pullRequest
 * @property-read ?ReviewFinding $finding
 */
#[Fillable(['pull_request_id', 'review_finding_id', 'in_reply_to_id', 'thread_comment_id', 'github_comment_id', 'role', 'author_login', 'body', 'status', 'tokens_input', 'tokens_output'])]
class ConversationMessage extends Model
{
    /** @use HasFactory<ConversationMessageFactory> */
    use HasFactory, MassPrunable;

    protected function casts(): array
    {
        return [
            'role' => ConversationRole::class,
            'status' => ConversationStatus::class,
            'thread_comment_id' => 'integer',
            'github_comment_id' => 'integer',
            'tokens_input' => 'integer',
            'tokens_output' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PullRequest, $this>
     */
    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }

    /**
     * @return BelongsTo<ReviewFinding, $this>
     */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(ReviewFinding::class, 'review_finding_id');
    }

    /**
     * @return BelongsTo<ConversationMessage, $this>
     */
    public function inReplyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'in_reply_to_id');
    }

    public function isInThread(): bool
    {
        return $this->thread_comment_id !== null;
    }

    /**
     * Old records are deleted by the scheduled "model:prune"; see config/retention.php.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('retention.conversation_days')));
    }
}
