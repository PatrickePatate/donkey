<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Repository;
use App\Models\User;

class RepositoryPolicy
{
    public function view(User $user, Repository $repository): bool
    {
        return $user->hasRoleIn($repository->organization, OrganizationRole::Viewer);
    }

    public function update(User $user, Repository $repository): bool
    {
        return $user->hasRoleIn($repository->organization, OrganizationRole::Member);
    }
}
