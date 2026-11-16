<?php

use App\Enums\GraphStatus;
use App\Enums\OrganizationRole;
use App\Jobs\BuildRepositoryGraph;
use App\Models\GithubInstallation;
use App\Models\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

require_once __DIR__.'/../GitHub/GitHubAppTestHelpers.php';

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

it('shows the code graph status of each repository', function () {
    [$member, $organization] = userInOrganization(OrganizationRole::Member);
    $repository = repositoryFor($organization);
    $repository->graphs()->create([
        'commit_sha' => str_repeat('f', 40),
        'status' => GraphStatus::Ready,
        'nodes_count' => 1200,
        'built_at' => now()->subHour(),
    ]);

    Livewire::actingAs($member)
        ->test('pages::repositories.index')
        ->assertSee('Ready')
        ->assertSee('fffffff');
});

it('lets members rebuild the graph from the default branch head', function () {
    Queue::fake([BuildRepositoryGraph::class]);
    configureGitHubApp();
    config(['graph.enabled' => true]);
    Cache::put('kodus-graph:available', true);
    [$member, $organization] = userInOrganization(OrganizationRole::Member);
    $repository = repositoryFor($organization);
    $repository->update(['full_name' => 'acme/app', 'default_branch' => 'develop']);
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 't'], 201),
        'api.github.com/repos/acme/app/commits/develop' => Http::response(['sha' => str_repeat('d', 40)]),
    ]);

    Livewire::actingAs($member)
        ->test('pages::repositories.index')
        ->call('rebuildGraph', $repository->id);

    Queue::assertPushed(BuildRepositoryGraph::class, fn (BuildRepositoryGraph $job) => $job->commitSha === str_repeat('d', 40));
});

it('warns when kodus-graph is not installed', function () {
    config(['graph.enabled' => true]);
    Cache::put('kodus-graph:available', false);
    [$member, $organization] = userInOrganization(OrganizationRole::Member);
    repositoryFor($organization);

    Livewire::actingAs($member)
        ->test('pages::repositories.index')
        ->assertSee('Code graph unavailable');
});
