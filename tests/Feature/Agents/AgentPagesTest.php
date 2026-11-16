<?php

use App\Enums\ConversationRole;
use App\Enums\FindingSeverity;
use App\Enums\OrganizationRole;
use App\Enums\ReviewTrigger;
use App\Jobs\ReviewPullRequest;
use App\Models\ConversationMessage;
use App\Models\GithubInstallation;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\ReviewAgent;
use App\Models\ReviewRun;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function agentTestPullRequest($organization, array $attributes = []): PullRequest
{
    $installation = GithubInstallation::factory()->for($organization)->create();
    $repository = Repository::factory()->for($installation, 'installation')->create(['organization_id' => $organization->id]);

    return PullRequest::factory()->for($repository)->create($attributes);
}

it('lists the organization agents only', function () {
    [$user, $organization] = userInOrganization(OrganizationRole::Viewer);
    ReviewAgent::factory()->create(['organization_id' => $organization->id, 'name' => 'Security']);
    [, $other] = userInOrganization();
    ReviewAgent::factory()->create(['organization_id' => $other->id, 'name' => 'Someone else’s agent', 'slug' => 'other']);

    $this->actingAs($user)
        ->get(route('agents.index'))
        ->assertOk()
        ->assertSee('Security')
        ->assertDontSee('Someone else’s agent')
        ->assertDontSee('New agent');
});

it('creates an agent from a template', function () {
    [$user, $organization] = userInOrganization(OrganizationRole::Admin);

    Livewire::actingAs($user)
        ->withQueryParams(['template' => 'security'])
        ->test('pages::agents.edit')
        ->assertSet('name', 'Security')
        ->assertSet('categories', ['security'])
        ->set('fileGlobs', "app/Http/*\n*.php\n")
        ->set('runOnAutoReview', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $agent = ReviewAgent::sole();

    expect($agent)
        ->organization_id->toBe($organization->id)
        ->slug->toBe('security')
        ->file_globs->toBe(['app/Http/*', '*.php'])
        ->severity_floor->toBe(FindingSeverity::Medium)
        ->run_on_auto_review->toBeTrue()
        ->and($agent->matchesPath('app/Http/Controllers/BillingController.php'))->toBeTrue()
        ->and($agent->matchesPath('resources/js/app.js'))->toBeFalse();
});

it('keeps slugs unique per scope', function () {
    [$user, $organization] = userInOrganization(OrganizationRole::Admin);
    ReviewAgent::factory()->create(['organization_id' => $organization->id, 'slug' => 'security']);
    $repository = agentTestPullRequest($organization)->repository;

    $component = Livewire::actingAs($user)
        ->test('pages::agents.edit')
        ->set('name', 'Security')
        ->set('slug', 'security')
        ->set('instructions', 'Look for injection and broken access control.')
        ->call('save')
        ->assertHasErrors(['slug' => 'unique']);

    $component->set('repositoryId', (string) $repository->id)->call('save')->assertHasNoErrors();

    expect(ReviewAgent::where('repository_id', $repository->id)->sole()->slug)->toBe('security');
});

it('only lets admins create and edit agents', function () {
    [$user, $organization] = userInOrganization(OrganizationRole::Member);
    $agent = ReviewAgent::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user)->get(route('agents.create'))->assertForbidden();

    Livewire::actingAs($user)
        ->test('pages::agents.edit', ['agent' => $agent])
        ->set('name', 'Renamed')
        ->call('save')
        ->assertForbidden();
});

it('hides agents of other organizations', function () {
    [$user] = userInOrganization(OrganizationRole::Admin);
    [, $other] = userInOrganization();
    $agent = ReviewAgent::factory()->create(['organization_id' => $other->id]);

    $this->actingAs($user)->get(route('agents.edit', $agent))->assertForbidden();
});

it('test-runs a saved agent on an open pull request without posting', function () {
    Queue::fake([ReviewPullRequest::class]);
    [$user, $organization] = userInOrganization(OrganizationRole::Member);
    $agent = ReviewAgent::factory()->create(['organization_id' => $organization->id]);
    $pullRequest = agentTestPullRequest($organization);
    $closed = agentTestPullRequest($organization, ['state' => 'closed']);

    Livewire::actingAs($user)
        ->test('pages::agents.edit', ['agent' => $agent])
        ->set('testPullRequestId', (string) $closed->id)
        ->call('test')
        ->assertHasErrors('testPullRequestId')
        ->set('testPullRequestId', (string) $pullRequest->id)
        ->call('test')
        ->assertHasNoErrors();

    expect(ReviewRun::sole())
        ->review_agent_id->toBe($agent->id)
        ->is_dry_run->toBeTrue()
        ->pull_request_id->toBe($pullRequest->id);
});

it('runs an agent and pauses auto-review from the pull request page', function () {
    Queue::fake([ReviewPullRequest::class]);
    [$user, $organization] = userInOrganization(OrganizationRole::Member);
    $pullRequest = agentTestPullRequest($organization);
    $agent = ReviewAgent::factory()->create(['organization_id' => $organization->id, 'name' => 'Security']);

    Livewire::actingAs($user)
        ->test('pages::pull-requests.show', ['pullRequest' => $pullRequest])
        ->assertSee('Run agent')
        ->call('runAgent', $agent->id)
        ->call('toggleAutoReview')
        ->assertSee('Auto-review paused');

    expect(ReviewRun::sole())
        ->review_agent_id->toBe($agent->id)
        ->trigger->toBe(ReviewTrigger::Manual)
        ->is_dry_run->toBeFalse()
        ->and($pullRequest->fresh()->auto_review_paused)->toBeTrue();
});

it('shows the conversation with the bot on the pull request page', function () {
    [$user, $organization] = userInOrganization(OrganizationRole::Viewer);
    $pullRequest = agentTestPullRequest($organization);
    ReviewRun::factory()->for($pullRequest)->create();
    $question = ConversationMessage::factory()->for($pullRequest)->create(['author_login' => 'grace', 'body' => 'Why is this risky?']);
    ConversationMessage::factory()->for($pullRequest)->fromBot()->create(['in_reply_to_id' => $question->id, 'body' => 'Because **callers** break.', 'role' => ConversationRole::Bot]);

    $this->actingAs($user)
        ->get(route('pull-requests.show', $pullRequest))
        ->assertOk()
        ->assertSee('Why is this risky?')
        ->assertSee('<strong>callers</strong>', false)
        ->assertDontSee('Run agent');
});
