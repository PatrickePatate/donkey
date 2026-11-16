<?php

use App\Enums\FindingStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewRunStatus;
use App\Enums\ReviewStage;
use App\Enums\ReviewTrigger;
use App\Jobs\ReviewPullRequest;
use App\Models\GithubInstallation;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function pullRequestIn($organization, array $attributes = []): PullRequest
{
    $installation = GithubInstallation::factory()->for($organization)->create();
    $repository = Repository::factory()->for($installation, 'installation')->create(['organization_id' => $organization->id]);

    return PullRequest::factory()->for($repository)->create($attributes);
}

it('lists the organization pull requests with their latest review', function () {
    [$user, $organization] = userInOrganization(OrganizationRole::Viewer);
    $pullRequest = pullRequestIn($organization, ['title' => 'Add billing']);
    ReviewRun::factory()->for($pullRequest)->create(['status' => ReviewRunStatus::Running, 'stage' => ReviewStage::Analyzing]);
    [, $other] = userInOrganization();
    pullRequestIn($other, ['title' => 'Someone else’s PR']);

    $this->actingAs($user)
        ->get(route('pull-requests.index'))
        ->assertOk()
        ->assertSee('Add billing')
        ->assertSee('Analyze…')
        ->assertDontSee('Someone else’s PR');
});

it('shows a review with its stepper, summary and published findings', function () {
    [$user, $organization] = userInOrganization(OrganizationRole::Viewer);
    $pullRequest = pullRequestIn($organization);
    $run = ReviewRun::factory()->for($pullRequest)->create([
        'status' => ReviewRunStatus::Running,
        'stage' => ReviewStage::Validating,
        'summary' => 'Adds **billing**.',
    ]);
    ReviewFinding::factory()->for($run)->create(['title' => 'Posted finding', 'status' => FindingStatus::Published]);
    ReviewFinding::factory()->for($run)->create(['title' => 'Quiet finding', 'status' => FindingStatus::Suppressed]);

    $this->actingAs($user)
        ->get(route('pull-requests.show', $pullRequest))
        ->assertOk()
        ->assertSee('data-state="complete"', false)
        ->assertSee('aria-current="step"', false)
        ->assertSee('<strong>billing</strong>', false)
        ->assertSee('Posted finding')
        ->assertDontSee('Quiet finding');
});

it('strips raw HTML from model-written markdown', function () {
    [$user, $organization] = userInOrganization(OrganizationRole::Viewer);
    $pullRequest = pullRequestIn($organization);
    ReviewRun::factory()->completed()->for($pullRequest)->create(['summary' => 'Hi <script>alert(1)</script>']);

    $this->actingAs($user)
        ->get(route('pull-requests.show', $pullRequest))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false);
});

it('filters findings by status', function () {
    [$user, $organization] = userInOrganization(OrganizationRole::Viewer);
    $pullRequest = pullRequestIn($organization);
    $run = ReviewRun::factory()->completed()->for($pullRequest)->create();
    ReviewFinding::factory()->for($run)->create(['title' => 'Quiet finding', 'status' => FindingStatus::Suppressed]);

    Livewire::actingAs($user)
        ->test('pages::pull-requests.show', ['pullRequest' => $pullRequest])
        ->assertDontSee('Quiet finding')
        ->set('findingStatus', 'suppressed')
        ->assertSee('Quiet finding');
});

it('lets members start a full review from the dashboard', function () {
    Queue::fake([ReviewPullRequest::class]);
    [$user, $organization] = userInOrganization(OrganizationRole::Member);
    $pullRequest = pullRequestIn($organization);

    Livewire::actingAs($user)
        ->test('pages::pull-requests.show', ['pullRequest' => $pullRequest])
        ->call('startReview');

    $run = ReviewRun::sole();

    expect($run->trigger)->toBe(ReviewTrigger::Manual)
        ->and($run->is_full)->toBeTrue()
        ->and($run->triggered_by)->toBe($user->name);
    Queue::assertPushed(ReviewPullRequest::class);
});

it('forbids viewers from starting reviews', function () {
    Queue::fake([ReviewPullRequest::class]);
    [$user, $organization] = userInOrganization(OrganizationRole::Viewer);
    $pullRequest = pullRequestIn($organization);

    Livewire::actingAs($user)
        ->test('pages::pull-requests.show', ['pullRequest' => $pullRequest])
        ->call('startReview')
        ->assertForbidden();

    expect(ReviewRun::count())->toBe(0);
});

it('forbids pull requests of other organizations', function () {
    [$user] = userInOrganization();
    [, $other] = userInOrganization();

    $this->actingAs($user)->get(route('pull-requests.show', pullRequestIn($other)))->assertForbidden();
});
