<?php

namespace App\Models;

use App\Enums\AiFeature;
use App\Enums\ReviewRunStatus;
use App\Enums\ReviewStage;
use App\Enums\ReviewTrigger;
use App\Enums\RunGraphStatus;
use App\Events\ReviewRunUpdated;
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
 * @property ?int $review_agent_id
 * @property ?string $triggered_by
 * @property bool $is_full
 * @property bool $is_dry_run
 * @property ReviewRunStatus $status
 * @property ?ReviewStage $stage
 * @property ?int $risk_score
 * @property ?int $risk_threshold
 * @property ?RunGraphStatus $graph_status
 * @property ?string $graph_risk_level
 * @property ?int $graph_risk_score
 * @property ?array{changed_functions?: list<array<string, mixed>>, test_gaps?: int, impacted_functions?: int, impacted_files?: int, affected_flows?: int, error?: string} $graph_summary
 * @property int $findings_rejected
 * @property ?string $summary
 * @property int $files_reviewed
 * @property ?string $provider
 * @property ?string $model
 * @property int $tokens_input
 * @property int $tokens_output
 * @property ?int $github_review_id
 * @property ?int $github_check_run_id
 * @property ?int $github_comment_id
 * @property ?string $error
 * @property ?Carbon $started_at
 * @property ?Carbon $completed_at
 * @property Carbon $created_at
 * @property-read PullRequest $pullRequest
 * @property-read ?ReviewAgent $agent
 */
#[Fillable(['pull_request_id', 'idempotency_key', 'head_sha', 'base_sha', 'trigger', 'review_agent_id', 'triggered_by', 'is_full', 'is_dry_run', 'status', 'stage', 'risk_score', 'risk_threshold', 'graph_status', 'graph_risk_level', 'graph_risk_score', 'graph_summary', 'summary', 'files_reviewed', 'findings_rejected', 'provider', 'model', 'tokens_input', 'tokens_output', 'github_review_id', 'github_check_run_id', 'github_comment_id', 'error', 'started_at', 'completed_at'])]
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
            'is_dry_run' => 'boolean',
            'risk_score' => 'integer',
            'risk_threshold' => 'integer',
            'graph_status' => RunGraphStatus::class,
            'graph_risk_score' => 'integer',
            'graph_summary' => 'array',
            'findings_rejected' => 'integer',
            'files_reviewed' => 'integer',
            'tokens_input' => 'integer',
            'tokens_output' => 'integer',
            'github_review_id' => 'integer',
            'github_check_run_id' => 'integer',
            'github_comment_id' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ReviewRunLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ReviewRunLog::class);
    }

    /**
     * Add a line to the live log and tell the dashboard; logging must never break a review.
     */
    public function log(string $message, string $level = 'info'): void
    {
        rescue(function () use ($message, $level): void {
            $this->logs()->create(['message' => $message, 'level' => $level]);
            ReviewRunUpdated::dispatch($this);
        });
    }

    /**
     * @return BelongsTo<PullRequest, $this>
     */
    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }

    /**
     * The agent this run was dedicated to, or null for a regular review.
     *
     * @return BelongsTo<ReviewAgent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(ReviewAgent::class, 'review_agent_id');
    }

    /**
     * @return HasMany<ReviewFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(ReviewFinding::class);
    }

    /**
     * Estimated cost in millionths of a dollar: the metered review, agent and verification calls made on the pull
     * request while this run was going. Null before it starts; dry runs are not metered.
     */
    public function estimatedCostMicros(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return (int) AiUsage::query()
            ->where('pull_request_id', $this->pull_request_id)
            ->whereIn('feature', [AiFeature::Review, AiFeature::Agent, AiFeature::Verification])
            ->whereBetween('created_at', [$this->started_at, $this->completed_at ?? now()])
            ->sum('cost_micros');
    }

    public function durationInSeconds(): ?int
    {
        if ($this->started_at === null || $this->completed_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->completed_at);
    }
}
