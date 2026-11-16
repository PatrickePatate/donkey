<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\PullRequest;
use App\Models\User;

class PullRequestPolicy
{
    public function view(User $user, PullRequest $pullRequest): bool
    {
        return $user->hasRoleIn($pullRequest->repository->organization, OrganizationRole::Viewer);
    }

    /**
     * Starting a review spends model tokens, so it requires member access.
     */
    public function review(User $user, PullRequest $pullRequest): bool
    {
        return $user->hasRoleIn($pullRequest->repository->organization, OrganizationRole::Member);
    }
}
