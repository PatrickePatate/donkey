<?php

namespace App\Models;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\FindingVerification;
use Database\Factories\ReviewFindingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $review_run_id
 * @property int $pull_request_id
 * @property ?int $review_agent_id
 * @property string $fingerprint
 * @property FindingCategory $category
 * @property FindingSeverity $severity
 * @property int $confidence
 * @property int $risk_score
 * @property string $file_path
 * @property int $start_line
 * @property int $end_line
 * @property string $title
 * @property string $body
 * @property ?string $suggestion
 * @property ?string $code
 * @property ?string $graph_symbol
 * @property ?int $graph_callers
 * @property ?FindingVerification $verification
 * @property ?string $verifier_note
 * @property ?int $learning_id
 * @property FindingStatus $status
 * @property ?int $github_comment_id
 * @property ?string $dismissed_by
 * @property-read ReviewRun $reviewRun
 * @property-read PullRequest $pullRequest
 * @property-read ?ReviewAgent $agent
 * @property-read ?Learning $learning
 */
#[Fillable(['review_run_id', 'pull_request_id', 'review_agent_id', 'fingerprint', 'category', 'severity', 'confidence', 'risk_score', 'file_path', 'start_line', 'end_line', 'title', 'body', 'suggestion', 'code', 'graph_symbol', 'graph_callers', 'status', 'verification', 'verifier_note', 'learning_id', 'github_comment_id', 'dismissed_by'])]
class ReviewFinding extends Model
{
    /** @use HasFactory<ReviewFindingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'category' => FindingCategory::class,
            'severity' => FindingSeverity::class,
            'status' => FindingStatus::class,
            'verification' => FindingVerification::class,
            'graph_callers' => 'integer',
            'confidence' => 'integer',
            'risk_score' => 'integer',
            'start_line' => 'integer',
            'end_line' => 'integer',
            'github_comment_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ReviewRun, $this>
     */
    public function reviewRun(): BelongsTo
    {
        return $this->belongsTo(ReviewRun::class);
    }

    /**
     * @return BelongsTo<PullRequest, $this>
     */
    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }

    /**
     * @return BelongsTo<ReviewAgent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(ReviewAgent::class, 'review_agent_id');
    }

    /**
     * The team learning the verifier matched when it rejected this finding.
     *
     * @return BelongsTo<Learning, $this>
     */
    public function learning(): BelongsTo
    {
        return $this->belongsTo(Learning::class);
    }

    /**
     * @return HasMany<FindingSignal, $this>
     */
    public function signals(): HasMany
    {
        return $this->hasMany(FindingSignal::class);
    }

    /**
     * @return HasMany<ConversationMessage, $this>
     */
    public function conversationMessages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function lineLabel(): string
    {
        return $this->start_line === $this->end_line
            ? "L{$this->start_line}"
            : "L{$this->start_line}-L{$this->end_line}";
    }
}
