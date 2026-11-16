<?php

namespace App\Models;

use App\Enums\ReviewRunStatus;
use App\Enums\ReviewStage;
use App\Enums\ReviewTrigger;
use Database\Factories\ReviewRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $pull_request_id
 * @property string $idempotency_key
 * @property string $head_sha
 * @property ?string $base_sha
 * @property ReviewTrigger $trigger
 * @property ?string $triggered_by
 * @property bool $is_full
 * @property ReviewRunStatus $status
 * @property ?ReviewStage $stage
 * @property ?int $risk_score
 * @property ?int $risk_threshold
 * @property ?string $summary
 * @property int $files_reviewed
 * @property ?string $provider
 * @property ?string $model
 * @property int $tokens_input
 * @property int $tokens_output
 * @property ?int $github_review_id
 * @property ?int $github_check_run_id
 * @property ?string $error
 * @property ?Carbon $started_at
 * @property ?Carbon $completed_at
 * @property Carbon $created_at
 * @property-read PullRequest $pullRequest
 */
#[Fillable(['pull_request_id', 'idempotency_key', 'head_sha', 'base_sha', 'trigger', 'triggered_by', 'is_full', 'status', 'stage', 'risk_score', 'risk_threshold', 'summary', 'files_reviewed', 'provider', 'model', 'tokens_input', 'tokens_output', 'github_review_id', 'github_check_run_id', 'error', 'started_at', 'completed_at'])]
class ReviewRun extends Model
{
    /** @use HasFactory<ReviewRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'trigger' => ReviewTrigger::class,
            'status' => ReviewRunStatus::class,
            'stage' => ReviewStage::class,
            'is_full' => 'boolean',
            'risk_score' => 'integer',
            'risk_threshold' => 'integer',
            'files_reviewed' => 'integer',
            'tokens_input' => 'integer',
            'tokens_output' => 'integer',
            'github_review_id' => 'integer',
            'github_check_run_id' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return HasMany<ReviewFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(ReviewFinding::class);
    }

    public function durationInSeconds(): ?int
    {
        if ($this->started_at === null || $this->completed_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->completed_at);
    }
}
