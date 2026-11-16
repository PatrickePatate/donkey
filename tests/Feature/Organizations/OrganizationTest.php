<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

it('redirects users without an organization to onboarding', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('organizations.create'));
});

it('creates an organization owned by the creator', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::organizations.create')
        ->set('name', 'Acme Inc')
        ->call('create')
        ->assertRedirect(route('repositories.index'));

    $organization = Organization::firstWhere('name', 'Acme Inc');

    expect($organization->slug)->toBe('acme-inc')
        ->and($user->fresh()->current_organization_id)->toBe($organization->id)
        ->and($organization->roleOf($user))->toBe(OrganizationRole::Owner);
});

it('renders the main pages for a member', function (string $route) {
    [$user] = userInOrganization(OrganizationRole::Member);

    $this->actingAs($user)->get(route($route))->assertOk();
})->with(['dashboard', 'repositories.index', 'settings.organization', 'settings.members']);

it('switches to an organization the user belongs to', function () {
    [$user] = userInOrganization();
    $other = Organization::factory()->create();
    $other->addMember($user, OrganizationRole::Viewer);

    $this->actingAs($user)->put(route('organizations.switch', $other))->assertRedirect(route('dashboard'));

    expect($user->fresh()->current_organization_id)->toBe($other->id);
});

it('refuses to switch to a foreign organization', function () {
    [$user, $organization] = userInOrganization();

    $this->actingAs($user)->put(route('organizations.switch', Organization::factory()->create()))->assertForbidden();

    expect($user->fresh()->current_organization_id)->toBe($organization->id);
});

it('lets admins update organization settings', function () {
    [$user, $organization] = userInOrganization(OrganizationRole::Admin);

    Livewire::actingAs($user)
        ->test('pages::settings.organization')
        ->set('riskThreshold', 70)
        ->set('autoReviewDefault', false)
        ->set('botHandle', 'reviewer')
        ->call('save')
        ->assertHasNoErrors();

    expect($organization->fresh())
        ->risk_threshold->toBe(70)
        ->auto_review_default->toBeFalse()
        ->bot_handle->toBe('reviewer');
});

it('forbids members from updating organization settings', function () {
    [$user] = userInOrganization(OrganizationRole::Member);

    Livewire::actingAs($user)
        ->test('pages::settings.organization')
        ->set('riskThreshold', 10)
        ->call('save')
        ->assertForbidden();
});
