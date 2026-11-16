<?php

namespace App\Models;

use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['organization_id', 'github_installation_id', 'github_id', 'full_name', 'default_branch', 'is_private', 'auto_review_enabled', 'risk_threshold'])]
class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'github_id' => 'integer',
            'is_private' => 'boolean',
            'auto_review_enabled' => 'boolean',
            'risk_threshold' => 'integer',
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
     * @return BelongsTo<GithubInstallation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(GithubInstallation::class, 'github_installation_id');
    }

    /**
     * @return HasMany<PullRequest, $this>
     */
    public function pullRequests(): HasMany
    {
        return $this->hasMany(PullRequest::class);
    }

    /**
     * @return HasMany<ReviewAgent, $this>
     */
    public function reviewAgents(): HasMany
    {
        return $this->hasMany(ReviewAgent::class);
    }

    /**
     * @return HasMany<RepositoryGraph, $this>
     */
    public function graphs(): HasMany
    {
        return $this->hasMany(RepositoryGraph::class);
    }

    /**
     * @return HasOne<RepositoryGraph, $this>
     */
    public function latestGraph(): HasOne
    {
        return $this->hasOne(RepositoryGraph::class)->latestOfMany();
    }

    /**
     * Whether auto-review runs, falling back to the organization default.
     */
    public function autoReviewEnabled(): bool
    {
        return $this->auto_review_enabled ?? $this->organization->auto_review_default;
    }

    /**
     * The risk threshold in effect, falling back to the organization default.
     */
    public function effectiveRiskThreshold(): int
    {
        return $this->risk_threshold ?? $this->organization->risk_threshold;
    }

    public function overridesRiskThreshold(): bool
    {
        return $this->risk_threshold !== null;
    }

    public function overridesAutoReview(): bool
    {
        return $this->auto_review_enabled !== null;
    }
}
