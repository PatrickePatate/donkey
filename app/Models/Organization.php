<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'risk_threshold', 'auto_review_default', 'bot_handle'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'risk_threshold' => 'integer',
            'auto_review_default' => 'boolean',
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
