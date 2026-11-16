<?php

use App\Actions\StartReview;
use App\Ai\Agents\CodeReviewer;
use App\Enums\FindingStatus;
use App\Enums\ReviewRunStatus;
use App\Enums\ReviewTrigger;
use App\Jobs\ReviewPullRequest;
use App\Models\ReviewAgent;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/ReviewTestHelpers.php';

beforeEach(function () {
    setUpReviewFixture($this);

    $this->agent = ReviewAgent::factory()->create([
        'organization_id' => $this->repository->organization_id,
        'name' => 'Security',
        'slug' => 'security',
        'categories' => ['security'],
    ]);
});

function agentRun(object $test, array $attributes = []): ReviewRun
{
    return ReviewRun::factory()->for($test->pullRequest)->create([
        'head_sha' => str_repeat('a', 40),
        'review_agent_id' => $test->agent->id,
        'is_full' => true,
        ...$attributes,
    ]);
}

function postedReviews(): int
{
    return collect(Http::recorded())
        ->filter(fn (array $pair) => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/pulls/7/reviews'))
        ->count();
}

it('runs an agent with its own focus and labels what it posts', function () {
    fakeGitHubForReview();
    CodeReviewer::fake([reviewerResponse([
        sqlInjectionFinding(),
        sqlInjectionFinding(['category' => 'performance', 'title' => 'Outside the agent focus']),
    ])]);

    runReview($run = agentRun($this));

    $finding = $run->fresh()->findings()->sole();

    expect($run->fresh()->status)->toBe(ReviewRunStatus::Completed)
        ->and($finding->review_agent_id)->toBe($this->agent->id)
        ->and($finding->status)->toBe(FindingStatus::Published)
        ->and($this->pullRequest->fresh()->last_reviewed_sha)->toBeNull();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/pulls/7/reviews')
        && str_contains($request['comments'][0]['body'], 'Security agent'));
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/issues/comments/555')
        && str_contains($request['body'], 'Donkey · Security agent'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/check-runs')
        && $request['name'] === 'Donkey review · Security');
});

it('gives the reviewer the agent instructions and restricts its categories', function () {
    $this->agent->update(['instructions' => 'Check every query for tenant scoping.']);
    $reviewer = new CodeReviewer($this->agent->fresh());

    expect($reviewer->instructions())
        ->toContain('Specialist focus: Security')
        ->toContain('Check every query for tenant scoping.')
        ->and((new CodeReviewer)->instructions())->not->toContain('Specialist focus');
});

it('skips an agent run when no changed file matches its globs', function () {
    fakeGitHubForReview();
    CodeReviewer::fake()->preventStrayPrompts();
    $this->agent->update(['file_globs' => ['resources/js/*']]);

    runReview($run = agentRun($this));

    expect($run->fresh())
        ->status->toBe(ReviewRunStatus::Skipped)
        ->summary->toContain('No changed files match the Security agent');
    CodeReviewer::assertNeverPrompted();
});

it('posts nothing on GitHub during a test run, and does not hide later findings', function () {
    fakeGitHubForReview();
    CodeReviewer::fake([reviewerResponse([sqlInjectionFinding()]), reviewerResponse([sqlInjectionFinding()])]);

    runReview($dryRun = agentRun($this, ['is_dry_run' => true]));

    expect($dryRun->fresh()->status)->toBe(ReviewRunStatus::Completed)
        ->and($dryRun->fresh()->findings()->sole()->status)->toBe(FindingStatus::Published)
        ->and(postedReviews())->toBe(0);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/check-runs'));

    runReview($this->run);

    expect($this->run->fresh()->findings()->sole()->status)->toBe(FindingStatus::Published)
        ->and(postedReviews())->toBe(1);
});

it('adds agents that join automatic reviews when their files changed', function () {
    fakeGitHubForReview();
    $this->agent->update(['run_on_auto_review' => true]);
    ReviewAgent::factory()->onAutoReview()->create([
        'organization_id' => $this->repository->organization_id,
        'slug' => 'frontend',
        'name' => 'Frontend',
        'file_globs' => ['resources/js/*'],
    ]);

    CodeReviewer::fake([
        reviewerResponse([]),
        reviewerResponse([sqlInjectionFinding()]),
    ])->preventStrayPrompts();

    runReview($this->run);

    $finding = $this->run->fresh()->findings()->sole();

    expect($finding->review_agent_id)->toBe($this->agent->id)
        ->and($finding->status)->toBe(FindingStatus::Published);
    CodeReviewer::assertPrompted(fn ($prompt) => $prompt->contains('app/Billing.php'));
});

it('never raises a finding again once it was dismissed on GitHub', function () {
    fakeGitHubForReview();
    CodeReviewer::fake([reviewerResponse([sqlInjectionFinding()]), reviewerResponse([sqlInjectionFinding()])]);

    runReview($this->run);
    $this->run->findings()->sole()->update(['status' => FindingStatus::Dismissed, 'dismissed_by' => 'grace']);

    $second = ReviewRun::factory()->for($this->pullRequest)->create(['head_sha' => str_repeat('a', 40), 'is_full' => true]);
    runReview($second);

    expect($second->fresh()->findings()->sole()->status)->toBe(FindingStatus::Dismissed)
        ->and(ReviewFinding::where('status', FindingStatus::Published)->count())->toBe(0);
});

it('lets a repository agent replace the organization agent with the same slug', function () {
    $repositoryAgent = ReviewAgent::factory()->create([
        'organization_id' => $this->repository->organization_id,
        'repository_id' => $this->repository->id,
        'slug' => 'security',
        'name' => 'Security (billing)',
    ]);
    ReviewAgent::factory()->create(['organization_id' => $this->repository->organization_id, 'slug' => 'off', 'enabled' => false]);

    expect(ReviewAgent::availableFor($this->repository)->pluck('id')->all())->toBe([$repositoryAgent->id])
        ->and(ReviewAgent::findForRepository($this->repository, 'SECURITY')?->id)->toBe($repositoryAgent->id);
});

it('queues one agent run per comment and always reviews the whole pull request', function () {
    Queue::fake([ReviewPullRequest::class]);

    $run = app(StartReview::class)->handle($this->pullRequest, ReviewTrigger::Comment, 'grace', source: '555', agent: $this->agent);
    $again = app(StartReview::class)->handle($this->pullRequest, ReviewTrigger::Comment, 'grace', source: '555', agent: $this->agent);

    expect($run)->not->toBeNull()
        ->is_full->toBeTrue()
        ->review_agent_id->toBe($this->agent->id)
        ->and($again)->toBeNull();
    Queue::assertPushed(ReviewPullRequest::class, 1);
});
