<?php

use App\Enums\FindingStatus;
use App\Enums\LearningStatus;
use App\Enums\OrganizationRole;
use App\Enums\SignalType;
use App\Models\FindingSignal;
use App\Models\GithubInstallation;
use App\Models\Learning;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\RepositoryMemory;
use App\Models\ReviewAgent;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
use App\Services\Knowledge\QualityMetrics;
use Livewire\Livewire;

function knowledgeRepository($organization): Repository
{
    $installation = GithubInstallation::factory()->for($organization)->create();

    return Repository::factory()->for($installation, 'installation')->create(['organization_id' => $organization->id, 'full_name' => 'acme/app']);
}

it('shows proposed learnings in the inbox with the findings they come from', function () {
    [$user, $organization] = userInOrganization(OrganizationRole::Viewer);
    $repository = knowledgeRepository($organization);
    $finding = ReviewFinding::factory()->for(ReviewRun::factory()->for(PullRequest::factory()->for($repository)), 'reviewRun')->create(['title' => 'Raw SQL in revenue report']);
    Learning::factory()->for($organization)->proposed()->create(['body' => 'Report SQL is safe.', 'source_finding_ids' => [$finding->id]]);
    Learning::factory()->for(knowledgeRepository(userInOrganization()[1])->organization)->proposed()->create(['body' => 'Someone else’s learning.']);

    $this->actingAs($user)
        ->get(route('knowledge.index'))
        ->assertOk()
        ->assertSee('Report SQL is safe.')
        ->assertSee('Raw SQL in revenue report')
        ->assertDontSee('Someone else’s learning.')
        ->assertDontSee('Approve');
});

it('lets admins approve, edit and reject learnings', function () {
    [$user, $organization] = userInOrganization(OrganizationRole::Admin);
    $repository = knowledgeRepository($organization);
    $approved = Learning::factory()->for($organization)->proposed()->create(['weight' => 0.4]);
    $rejected = Learning::factory()->for($organization)->proposed()->create();

    Livewire::actingAs($user)
        ->test('pages::knowledge.index')
        ->call('approve', $approved->id)
        ->call('reject', $rejected->id)
        ->call('editLearning', $approved->id)
        ->set('learningBody', 'Raw SQL in app/Reports is built from constants.')
        ->set('learningRepositoryId', (string) $repository->id)
        ->set('learningPathGlob', 'app/Reports/*')
        ->call('saveLearning')
        ->assertHasNoErrors();

    expect($approved->fresh())
        ->status->toBe(LearningStatus::Active)
        ->reviewed_by->toBe($user->id)
        ->weight->toBe(1.0)
        ->body->toBe('Raw SQL in app/Reports is built from constants.')
        ->repository_id->toBe($repository->id)
        ->path_glob->toBe('app/Reports/*')
        ->and($rejected->fresh()->status)->toBe(LearningStatus::Rejected);
});

it('does not let members approve learnings', function () {
    [$user, $organization] = userInOrganization(OrganizationRole::Member);
    $learning = Learning::factory()->for($organization)->proposed()->create();

    Livewire::actingAs($user)->test('pages::knowledge.index')->call('approve', $learning->id)->assertForbidden();

    expect($learning->fresh()->status)->toBe(LearningStatus::Proposed);
});

it('lets members add memories and remove their own', function () {
    [$user, $organization] = userInOrganization(OrganizationRole::Member);
    $repository = knowledgeRepository($organization);
    $someoneElses = RepositoryMemory::factory()->for($repository)->create();

    $component = Livewire::actingAs($user)
        ->test('pages::knowledge.index', ['tab' => 'memories'])
        ->set('memoryRepositoryId', (string) $repository->id)
        ->set('memoryBody', 'Money is stored in cents.')
        ->set('memoryPathGlob', 'app/Billing/*')
        ->set('memoryPinned', true)
        ->call('addMemory')
        ->assertHasNoErrors();

    $memory = RepositoryMemory::where('body', 'Money is stored in cents.')->sole();

    expect($memory)
        ->user_id->toBe($user->id)
        ->pinned->toBeTrue()
        ->path_glob->toBe('app/Billing/*');

    $component->call('deleteMemory', $memory->id)->assertOk();

    Livewire::actingAs($user)
        ->test('pages::knowledge.index', ['tab' => 'memories'])
        ->call('deleteMemory', $someoneElses->id)
        ->assertForbidden();

    expect(RepositoryMemory::count())->toBe(1);
});

it('shows review quality on the dashboard', function () {
    [$user, $organization] = userInOrganization(OrganizationRole::Viewer);
    $repository = knowledgeRepository($organization);
    $run = ReviewRun::factory()->for(PullRequest::factory()->for($repository))->create();
    $agent = ReviewAgent::factory()->create(['organization_id' => $organization->id, 'name' => 'Security']);

    $accepted = ReviewFinding::factory()->for($run, 'reviewRun')->create(['category' => 'bug']);
    FindingSignal::factory()->for($accepted, 'finding')->create(['type' => SignalType::Applied]);
    FindingSignal::factory()->for($accepted, 'finding')->create(['type' => SignalType::ThumbsDown]);
    $rejected = ReviewFinding::factory()->for($run, 'reviewRun')->create(['category' => 'security', 'status' => FindingStatus::Dismissed, 'review_agent_id' => $agent->id]);
    FindingSignal::factory()->for($rejected, 'finding')->create(['type' => SignalType::Dismissed]);
    $ignored = ReviewFinding::factory()->for($run, 'reviewRun')->create(['category' => 'bug']);
    FindingSignal::factory()->for($ignored, 'finding')->create(['type' => SignalType::IgnoredOnMerge]);
    ReviewFinding::factory()->for($run, 'reviewRun')->create(['status' => FindingStatus::Rejected, 'learning_id' => Learning::factory()->for($organization)]);
    ReviewFinding::factory()->for(ReviewRun::factory()->for($run->pullRequest)->state(['is_dry_run' => true]), 'reviewRun')->create();
    Learning::factory()->for($organization)->proposed()->create();

    $quality = app(QualityMetrics::class)->forOrganization($organization);

    expect($quality)
        ->posted->toBe(3)
        ->accepted->toBe(1)
        ->rejected->toBe(1)
        ->ignored->toBe(1)
        ->acceptance_rate->toBe(33)
        ->filtered_by_learnings->toBe(1)
        ->rejected_by_verifier->toBe(1)
        ->and(collect($quality['by_reviewer'])->firstWhere('label', 'Security'))->toMatchArray(['posted' => 1, 'rejected' => 1]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Review quality')
        ->assertSee('33%')
        ->assertSee('1 proposed learning to review');
});
