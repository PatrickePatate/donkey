<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $risk_threshold
 * @property bool $auto_review_default
 * @property bool $eco_mode
 * @property string $bot_handle
 * @property ?int $monthly_budget_cents
 * @property ?list<string> $auto_join_domains
 * @property bool $auto_join_enabled
 * @property OrganizationRole $auto_join_role
 * @property ?string $ai_provider
 * @property ?string $ai_api_key
 * @property ?string $review_model
 * @property ?string $eco_model
 * @property ?string $verifier_model
 * @property ?string $conversation_model
 */
#[Hidden(['ai_api_key'])]
#[Fillable(['name', 'slug', 'risk_threshold', 'auto_review_default', 'eco_mode', 'bot_handle', 'monthly_budget_cents', 'auto_join_domains', 'auto_join_enabled', 'auto_join_role', 'ai_provider', 'ai_api_key', 'review_model', 'eco_model', 'verifier_model', 'conversation_model'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'risk_threshold' => 'integer',
            'auto_review_default' => 'boolean',
            'eco_mode' => 'boolean',
            'monthly_budget_cents' => 'integer',
            'auto_join_domains' => 'array',
            'auto_join_enabled' => 'boolean',
            'auto_join_role' => OrganizationRole::class,
            'ai_api_key' => 'encrypted',
        ];
    }

    public function aiProvider(): string
    {
        return $this->ai_provider ?: 'anthropic';
    }

    public function hasAiKey(): bool
    {
        return filled($this->ai_api_key);
    }

    /**
     * The model for a role (review, eco, verifier or conversation): its own setting, else the review model, else the built-in default.
     */
    public function modelFor(string $role): string
    {
        $own = $role === 'review' ? $this->review_model : $this->{"{$role}_model"};

        return (string) ($own ?: $this->review_model ?: config($role === 'review' ? 'reviews.model' : "reviews.{$role}.model"));
    }

    /**
     * @return BelongsToMany<User, $this, Membership>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * @return HasMany<GithubInstallation, $this>
     */
    public function githubInstallations(): HasMany
    {
        return $this->hasMany(GithubInstallation::class);
    }

    /**
     * @return HasMany<ModelPrice, $this>
     */
    public function modelPrices(): HasMany
    {
        return $this->hasMany(ModelPrice::class);
    }

    /**
     * @return HasMany<AiUsage, $this>
     */
    public function aiUsages(): HasMany
    {
        return $this->hasMany(AiUsage::class);
    }

    /**
     * @return HasMany<AuditEvent, $this>
     */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    /**
     * @return HasMany<Learning, $this>
     */
    public function learnings(): HasMany
    {
        return $this->hasMany(Learning::class);
    }

    /**
     * @return HasMany<ReviewAgent, $this>
     */
    public function reviewAgents(): HasMany
    {
        return $this->hasMany(ReviewAgent::class);
    }

    /**
     * @return HasMany<Repository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    public function roleOf(User $user): ?OrganizationRole
    {
        return Membership::query()
            ->where('organization_id', $this->getKey())
            ->where('user_id', $user->getKey())
            ->first()
            ?->role;
    }

    public function addMember(User $user, OrganizationRole $role): void
    {
        $this->members()->syncWithoutDetaching([$user->getKey() => ['role' => $role->value]]);
    }

    public function ownersCount(): int
    {
        return $this->members()->wherePivot('role', OrganizationRole::Owner->value)->count();
    }
}
