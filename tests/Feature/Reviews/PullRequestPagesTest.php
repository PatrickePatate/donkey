<?php

use App\Enums\AiFeature;
use App\Enums\FindingStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewRunStatus;
use App\Enums\ReviewStage;
use App\Enums\ReviewTrigger;
use App\Jobs\ReviewPullRequest;
use App\Models\AiUsage;
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

it('shows the structural impact and verifier notes of a review', function () {
    [$user, $organization] = userInOrganization(OrganizationRole::Viewer);
    $pullRequest = pullRequestIn($organization);
    $run = ReviewRun::factory()->completed()->for($pullRequest)->create([
        'graph_status' => 'ready',
        'graph_risk_level' => 'HIGH',
        'graph_risk_score' => 81,
        'graph_summary' => [
            'changed_functions' => [[
                'name' => 'charge', 'symbol' => 'app/Billing.php::Billing.charge', 'file' => 'app/Billing.php',
                'start' => 6, 'end' => 9, 'callers' => 16, 'tested' => false,
                'contract' => [['field' => 'params', 'old' => '($user)', 'new' => '($user, $amount)']], 'impact' => null,
            ]],
            'test_gaps' => 1, 'impacted_functions' => 9, 'impacted_files' => 4, 'affected_flows' => 2,
        ],
    ]);
    ReviewFinding::factory()->for($run)->create([
        'title' => 'Imaginary race condition',
        'status' => FindingStatus::Rejected,
        'verification' => 'rejected',
        'verifier_note' => 'No shared state is touched.',
    ]);

    Livewire::actingAs($user)
        ->test('pages::pull-requests.show', ['pullRequest' => $pullRequest])
        ->assertSee('Structural risk')
        ->assertSee('High · 81')
        ->assertSee('16 callers')
        ->assertSee('($user, $amount)')
        ->set('findingStatus', 'rejected')
        ->assertSee('Imaginary race condition')
        ->assertSee('No shared state is touched.');
});

it('shows the estimated cost of the calls a review made', function () {
    [$user, $organization] = userInOrganization(OrganizationRole::Viewer);
    $pullRequest = pullRequestIn($organization);
    $run = ReviewRun::factory()->completed()->for($pullRequest)->create();
    $otherRun = ReviewRun::factory()->completed()->for($pullRequest)->create();
    $usage = ['organization_id' => $organization->id, 'pull_request_id' => $pullRequest->id];
    AiUsage::factory()->create([...$usage, 'review_run_id' => $run->id, 'cost_micros' => 1_200_000]);
    AiUsage::factory()->create([...$usage, 'review_run_id' => $run->id, 'feature' => AiFeature::Verification, 'cost_micros' => 300_000]);
    AiUsage::factory()->create([...$usage, 'review_run_id' => $otherRun->id, 'cost_micros' => 7_000_000]);
    AiUsage::factory()->create([...$usage, 'feature' => AiFeature::Conversation, 'cost_micros' => 5_000_000]);

    expect($run->estimatedCostMicros())->toBe(1_500_000);

    Livewire::actingAs($user)->test('pages::pull-requests.show', ['pullRequest' => $pullRequest])
        ->call('selectRun', $run->id)
        ->assertSee('Estimated cost')
        ->assertSee('$1.50');
});
