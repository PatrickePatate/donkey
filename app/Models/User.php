<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'current_organization_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsToMany<Organization, $this, Membership>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->using(Membership::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function belongsToOrganization(Organization $organization): bool
    {
        return $this->organizations()->whereKey($organization->getKey())->exists();
    }

    public function roleIn(Organization $organization): ?OrganizationRole
    {
        return $organization->roleOf($this);
    }

    public function hasRoleIn(Organization $organization, OrganizationRole $minimum): bool
    {
        return $this->roleIn($organization)?->isAtLeast($minimum) ?? false;
    }

    public function switchOrganization(Organization $organization): bool
    {
        if (! $this->belongsToOrganization($organization)) {
            return false;
        }

        $this->forceFill(['current_organization_id' => $organization->getKey()])->save();
        $this->setRelation('currentOrganization', $organization);

        return true;
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn (string $word): string => Str::upper(Str::substr($word, 0, 1)))
            ->implode('');
    }
}
