<?php

use App\Ai\Agents\CodeReviewer;
use App\Ai\Agents\FindingVerifier;
use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\OrganizationRole;
use App\Models\AiUsage;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\PullRequest;
use App\Models\ReviewAgent;
use App\Services\Graph\GraphContext;
use App\Services\Review\FilePatch;
use App\Services\Review\FindingVerifierService;
use App\Services\Review\ProposedFinding;
use App\Services\Review\ReviewAnalyzer;
use App\Services\Review\ReviewContext;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;

require_once __DIR__.'/ReviewTestHelpers.php';

it('provides an Eco-mode state for organization factories', function () {
    expect(Organization::factory()->ecoMode()->make()->eco_mode)->toBeTrue();
});

function ecoReviewContext(int $patchSize): ReviewContext
{
    $file = new FilePatch(
        path: 'app/Billing.php',
        status: 'modified',
        patch: str_repeat('x', $patchSize),
        commentableLines: [],
        hunkOfLine: [],
        addedLines: [],
    );

    return new ReviewContext(
        baseSha: str_repeat('b', 40),
        isIncremental: false,
        files: [$file->path => $file],
        instructions: [],
        skippedFiles: [],
    );
}

function analyzeEcoReview(PullRequest $pullRequest, int $patchSize = 100, ?GraphContext $graph = null, ?ReviewAgent $agent = null): void
{
    app(ReviewAnalyzer::class)->analyze($pullRequest, ecoReviewContext($patchSize), $graph, $agent);
}

function ecoFinding(FindingSeverity $severity): ProposedFinding
{
    return new ProposedFinding(
        path: 'app/Billing.php',
        startLine: 7,
        endLine: 7,
        category: FindingCategory::Security,
        severity: $severity,
        confidence: 90,
        title: 'Review candidate',
        body: 'A candidate finding for model routing.',
        suggestion: null,
        code: 'return $value;',
    );
}

/**
 * @param  array<int, ProposedFinding>  $findings
 */
function verifyEcoFindings(array $findings): void
{
    config(['reviews.verifier.enabled' => true]);
    fakeGitHubForReview();
    FindingVerifier::fake([['verdicts' => []]]);

    app(FindingVerifierService::class)->verify(test()->run, $findings);
}

it('keeps a small review chunk on the main model when Eco-mode is off', function () {
    setUpReviewFixture($this);
    CodeReviewer::fake([reviewerResponse([])]);

    analyzeEcoReview($this->pullRequest, patchSize: 8_000);

    expect(AiUsage::sole()->model)->toBe(config('reviews.model'));
    CodeReviewer::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === config('reviews.model'));
});

it('uses the Eco model for a small review chunk when Eco-mode is on', function () {
    setUpReviewFixture($this);
    $this->pullRequest->repository->organization->update(['eco_mode' => true]);
    CodeReviewer::fake([reviewerResponse([])]);

    analyzeEcoReview($this->pullRequest, patchSize: 8_000);

    expect(AiUsage::sole()->model)->toBe(config('reviews.eco.model'));
    CodeReviewer::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === config('reviews.eco.model'));
});

it('keeps non-persisted evaluation reviews on the main model without recording usage', function () {
    setUpReviewFixture($this);
    $this->pullRequest->repository->organization->update(['eco_mode' => true]);
    $this->pullRequest->exists = false;
    CodeReviewer::fake([reviewerResponse([])]);

    analyzeEcoReview($this->pullRequest);

    expect(AiUsage::count())->toBe(0);
    CodeReviewer::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === config('reviews.model'));
});

it('keeps a large review chunk on the main model in Eco-mode', function () {
    setUpReviewFixture($this);
    $this->pullRequest->repository->organization->update(['eco_mode' => true]);
    CodeReviewer::fake([reviewerResponse([])]);

    analyzeEcoReview($this->pullRequest, patchSize: 8_001);

    expect(AiUsage::sole()->model)->toBe(config('reviews.model'));
    CodeReviewer::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === config('reviews.model'));
});

it('keeps a small review chunk on the main model when graph risk is high', function () {
    setUpReviewFixture($this);
    $this->pullRequest->repository->organization->update(['eco_mode' => true]);
    CodeReviewer::fake([reviewerResponse([])]);
    $graph = new GraphContext('High-risk changes', 'HIGH', 80, [], 0, 0, 0, 0);

    analyzeEcoReview($this->pullRequest, graph: $graph);

    expect(AiUsage::sole()->model)->toBe(config('reviews.model'));
    CodeReviewer::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === config('reviews.model'));
});

it('honors an agent model instead of the Eco model', function () {
    setUpReviewFixture($this);
    $organization = $this->pullRequest->repository->organization;
    $organization->update(['eco_mode' => true]);
    $agent = ReviewAgent::factory()->for($organization)->create(['model' => 'specialist-model']);
    CodeReviewer::fake([reviewerResponse([])]);

    analyzeEcoReview($this->pullRequest, agent: $agent);

    expect(AiUsage::sole()->model)->toBe('specialist-model');
    CodeReviewer::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === 'specialist-model');
});

it('uses the Eco model to verify a few low-severity findings', function () {
    setUpReviewFixture($this);
    $this->pullRequest->repository->organization->update(['eco_mode' => true]);

    verifyEcoFindings([ecoFinding(FindingSeverity::Low)]);

    expect(AiUsage::sole()->model)->toBe(config('reviews.eco.model'));
    FindingVerifier::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === config('reviews.eco.model'));
});

it('keeps verifier batches larger than the configured limit on the main model', function () {
    setUpReviewFixture($this);
    $this->pullRequest->repository->organization->update(['eco_mode' => true]);

    verifyEcoFindings(array_fill(0, 4, ecoFinding(FindingSeverity::Low)));

    expect(AiUsage::sole()->model)->toBe(config('reviews.verifier.model'));
    FindingVerifier::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === config('reviews.verifier.model'));
});

it('keeps critical findings on the main verifier model in Eco-mode', function () {
    setUpReviewFixture($this);
    $this->pullRequest->repository->organization->update(['eco_mode' => true]);

    verifyEcoFindings([ecoFinding(FindingSeverity::Critical)]);

    expect(AiUsage::sole()->model)->toBe(config('reviews.verifier.model'));
    FindingVerifier::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === config('reviews.verifier.model'));
});

it('saves and audits the Eco-mode organization setting', function () {
    [$admin, $organization] = userInOrganization(OrganizationRole::Admin);

    Livewire::actingAs($admin)
        ->test('pages::settings.organization')
        ->set('ecoMode', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($organization->fresh()->eco_mode)->toBeTrue();
    expect(AuditEvent::sole()->metadata)->toBe(['eco_mode' => [false, true]]);
});
