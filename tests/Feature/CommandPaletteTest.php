<?php

use App\Enums\OrganizationRole;
use App\Models\GithubInstallation;
use App\Models\PullRequest;
use App\Models\Repository;
use Livewire\Livewire;

function paletteRepository($organization, string $name): Repository
{
    $installation = GithubInstallation::factory()->for($organization)->create();

    return Repository::factory()->for($installation, 'installation')->create(['organization_id' => $organization->id, 'full_name' => $name]);
}

it('finds pull requests by number and title only in the current organization', function () {
    [$user, $organization] = userInOrganization(OrganizationRole::Viewer);
    $repository = paletteRepository($organization, 'acme/app');
    PullRequest::factory()->for($repository)->create(['number' => 42, 'title' => 'Fix invoice rounding']);
    PullRequest::factory()->for($repository)->create(['number' => 7, 'title' => 'Add billing export']);
    PullRequest::factory()->for(paletteRepository(userInOrganization()[1], 'other/secret'))->create(['number' => 42, 'title' => 'Secret invoice work']);

    Livewire::actingAs($user)->test('command-palette')
        ->set('query', '#42')
        ->assertSee('Fix invoice rounding')
        ->assertDontSee('Add billing export')
        ->assertDontSee('Secret invoice work')
        ->set('query', 'invoice')
        ->assertSee('Fix invoice rounding')
        ->assertDontSee('Secret invoice work')
        ->assertDontSee('other/secret');
});

it('only offers member invitations to people who can manage members', function () {
    [$viewer] = userInOrganization(OrganizationRole::Viewer);
    [$admin] = userInOrganization(OrganizationRole::Admin);

    Livewire::actingAs($viewer)->test('command-palette')->assertDontSee('Invite member')->assertSee('Toggle theme');
    Livewire::actingAs($admin)->test('command-palette')->set('query', 'invite')->assertSee('Invite member')->assertDontSee('Toggle theme');
});
