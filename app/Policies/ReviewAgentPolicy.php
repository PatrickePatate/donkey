<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\ReviewAgent;
use App\Models\User;

/**
 * Everyone in the organization can see its agents; admins shape what they review.
 */
class ReviewAgentPolicy
{
    public function view(User $user, ReviewAgent $agent): bool
    {
        return $user->hasRoleIn($agent->organization, OrganizationRole::Viewer);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, OrganizationRole::Admin);
    }

    public function update(User $user, ReviewAgent $agent): bool
    {
        return $user->hasRoleIn($agent->organization, OrganizationRole::Admin);
    }

    public function delete(User $user, ReviewAgent $agent): bool
    {
        return $user->hasRoleIn($agent->organization, OrganizationRole::Admin);
    }

    /**
     * Trying an agent on a pull request spends model tokens but posts nothing.
     */
    public function test(User $user, ReviewAgent $agent): bool
    {
        return $user->hasRoleIn($agent->organization, OrganizationRole::Member);
    }
}
