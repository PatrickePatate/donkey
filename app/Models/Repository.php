<?php

namespace App\Models;

use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['organization_id', 'github_installation_id', 'github_id', 'full_name', 'default_branch', 'is_private', 'auto_review_enabled', 'auto_docs_enabled', 'risk_threshold', 'dependencies_scanned_at', 'dependencies_commit_sha', 'composer_packages_count', 'dependencies_scan_error', 'security_branch'])]
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
            'auto_docs_enabled' => 'boolean',
            'risk_threshold' => 'integer',
            'dependencies_scanned_at' => 'datetime',
            'composer_packages_count' => 'integer',
        ];
    }

    /**
     * The branch whose composer.lock the security scan checks.
     */
    public function securityBranch(): string
    {
        return $this->security_branch ?: $this->default_branch;
    }

    /**
     * @return HasMany<SecurityAdvisory, $this>
     */
    public function securityAdvisories(): HasMany
    {
        return $this->hasMany(SecurityAdvisory::class);
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
     * @return HasMany<RepositoryMemory, $this>
     */
    public function memories(): HasMany
    {
        return $this->hasMany(RepositoryMemory::class);
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
     * @return HasOne<RepositoryDocument, $this>
     */
    public function document(): HasOne
    {
        return $this->hasOne(RepositoryDocument::class);
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
