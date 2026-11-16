<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Repository;
use App\Models\RepositoryMemory;
use App\Models\User;

/**
 * Members teach the reviewer about their repositories; admins curate what everyone wrote.
 */
class RepositoryMemoryPolicy
{
    public function create(User $user, Repository $repository): bool
    {
        return $user->hasRoleIn($repository->organization, OrganizationRole::Member);
    }

    public function update(User $user, RepositoryMemory $memory): bool
    {
        return $user->hasRoleIn($memory->repository->organization, OrganizationRole::Admin)
            || ($memory->user_id === $user->id && $user->hasRoleIn($memory->repository->organization, OrganizationRole::Member));
    }

    public function delete(User $user, RepositoryMemory $memory): bool
    {
        return $this->update($user, $memory);
    }
}
