<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property OrganizationRole $auto_join_role
 */
#[Fillable(['name', 'slug', 'risk_threshold', 'auto_review_default', 'eco_mode', 'bot_handle', 'monthly_budget_cents', 'auto_join_domains', 'auto_join_role'])]
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
            'auto_join_role' => OrganizationRole::class,
        ];
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
