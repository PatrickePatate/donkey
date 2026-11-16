<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Learning;
use App\Models\User;

/**
 * Learnings change what every review says, so only admins approve or edit them.
 */
class LearningPolicy
{
    public function view(User $user, Learning $learning): bool
    {
        return $user->hasRoleIn($learning->organization, OrganizationRole::Viewer);
    }

    public function update(User $user, Learning $learning): bool
    {
        return $user->hasRoleIn($learning->organization, OrganizationRole::Admin);
    }

    public function delete(User $user, Learning $learning): bool
    {
        return $user->hasRoleIn($learning->organization, OrganizationRole::Admin);
    }
}
