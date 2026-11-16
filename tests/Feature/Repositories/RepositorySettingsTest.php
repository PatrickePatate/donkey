<?php

use App\Enums\OrganizationRole;
use App\Models\GithubInstallation;
use App\Models\Repository;
use Livewire\Livewire;

function repositoryFor($organization): Repository
{
    $installation = GithubInstallation::factory()->for($organization)->create();

    return Repository::factory()->for($installation, 'installation')->create(['organization_id' => $organization->id]);
}

it('falls back to organization defaults', function () {
    [, $organization] = userInOrganization();
    $organization->update(['risk_threshold' => 65, 'auto_review_default' => false]);
    $repository = repositoryFor($organization);

    expect($repository->fresh()->effectiveRiskThreshold())->toBe(65)
        ->and($repository->fresh()->autoReviewEnabled())->toBeFalse();
});

it('lets members override auto-review and the risk threshold', function () {
    [$member, $organization] = userInOrganization(OrganizationRole::Member);
    $repository = repositoryFor($organization);

    Livewire::actingAs($member)
        ->test('pages::repositories.index')
        ->assertSee($repository->full_name)
        ->call('setAutoReview', $repository->id, false)
        ->call('setRiskThreshold', $repository->id, '80');

    expect($repository->fresh())
        ->auto_review_enabled->toBeFalse()
        ->risk_threshold->toBe(80);
});

it('resets overrides back to the organization default', function () {
    [$member, $organization] = userInOrganization(OrganizationRole::Member);
    $repository = repositoryFor($organization);
    $repository->update(['auto_review_enabled' => false, 'risk_threshold' => 90]);

    Livewire::actingAs($member)
        ->test('pages::repositories.index')
        ->call('setAutoReview', $repository->id, null)
        ->call('setRiskThreshold', $repository->id, '');

    expect($repository->fresh())
        ->auto_review_enabled->toBeNull()
        ->risk_threshold->toBeNull();
});

it('rejects thresholds outside 0-100', function () {
    [$member, $organization] = userInOrganization(OrganizationRole::Member);
    $repository = repositoryFor($organization);

    Livewire::actingAs($member)
        ->test('pages::repositories.index')
        ->call('setRiskThreshold', $repository->id, '150');

    expect($repository->fresh()->risk_threshold)->toBeNull();
});

it('forbids viewers from changing repository settings', function () {
    [$viewer, $organization] = userInOrganization(OrganizationRole::Viewer);
    $repository = repositoryFor($organization);

    Livewire::actingAs($viewer)
        ->test('pages::repositories.index')
        ->call('setAutoReview', $repository->id, false)
        ->assertForbidden();
});

it('does not expose repositories of other organizations', function () {
    [$member] = userInOrganization(OrganizationRole::Member);
    [, $otherOrganization] = userInOrganization();
    $foreign = repositoryFor($otherOrganization);

    Livewire::actingAs($member)
        ->test('pages::repositories.index')
        ->assertDontSee($foreign->full_name)
        ->call('setAutoReview', $foreign->id, false)
        ->assertNotFound();
});
