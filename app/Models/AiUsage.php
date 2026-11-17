<?php

namespace App\Models;

use App\Enums\AiFeature;
use Database\Factories\AiUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One AI call, with its tokens and estimated cost in millionths of a dollar.
 *
 * @property int $id
 * @property int $organization_id
 * @property ?int $repository_id
 * @property ?int $pull_request_id
 * @property ?int $review_run_id
 * @property AiFeature $feature
 * @property ?string $provider
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cost_micros
 * @property Carbon $created_at
 * @property-read Organization $organization
 * @property-read ?Repository $repository
 * @property-read ?PullRequest $pullRequest
 */
#[Fillable(['organization_id', 'repository_id', 'pull_request_id', 'review_run_id', 'feature', 'provider', 'model', 'input_tokens', 'output_tokens', 'cost_micros', 'created_at'])]
class AiUsage extends Model
{
    /** @use HasFactory<AiUsageFactory> */
    use HasFactory, MassPrunable;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'feature' => AiFeature::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_micros' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * @return BelongsTo<PullRequest, $this>
     */
    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('retention.ai_usage_days')));
    }
}
