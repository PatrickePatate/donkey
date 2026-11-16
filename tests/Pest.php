<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Create a user who belongs to a fresh organization with the given role.
 *
 * @return array{0: User, 1: Organization}
 */
function userInOrganization(OrganizationRole $role = OrganizationRole::Owner): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->withMember($user, $role)->create();

    return [$user->fresh(), $organization];
}

/**
 * Sign a webhook body the way GitHub does.
 */
function githubSignature(string $body, string $secret = 'testing-secret'): string
{
    return 'sha256='.hash_hmac('sha256', $body, $secret);
}
