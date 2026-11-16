<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, OrganizationRole::Viewer);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, OrganizationRole::Admin);
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, OrganizationRole::Admin);
    }

    public function manageGithub(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, OrganizationRole::Admin);
    }

    /**
     * Spend, budgets and cost per repository are for owners only.
     */
    public function viewUsage(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, OrganizationRole::Owner);
    }

    /**
     * The AI provider, its API key and models are for owners only.
     */
    public function manageAi(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, OrganizationRole::Owner);
    }

    public function viewAudit(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, OrganizationRole::Admin);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, OrganizationRole::Owner);
    }
}
