<?php

use App\Ai\Agents\CodeReviewer;
use App\Ai\Agents\FindingVerifier;
use App\Ai\Agents\LearningSynthesizer;
use App\Enums\FindingStatus;
use App\Enums\LearningStatus;
use App\Enums\SignalType;
use App\Jobs\ProposeLearnings;
use App\Models\Learning;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\RepositoryMemory;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
use App\Services\Knowledge\SignalRecorder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

require_once __DIR__.'/../Reviews/ReviewTestHelpers.php';

beforeEach(fn () => setUpReviewFixture($this));

it('gives the reviewer pinned and relevant memories and the active learnings of the repository', function () {
    fakeGitHubForReview();
    $organization = $this->repository->organization;
    $otherRepository = Repository::factory()->create(['organization_id' => $organization->id]);

    RepositoryMemory::factory()->for($this->repository)->pinned()->create(['body' => 'Money is stored in cents.', 'path_glob' => 'database/*']);
    RepositoryMemory::factory()->for($this->repository)->create(['body' => 'Billing talks to Stripe.', 'path_glob' => 'app/*']);
    RepositoryMemory::factory()->for($this->repository)->create(['body' => 'Frontend uses Alpine.', 'path_glob' => 'resources/js/*']);
    RepositoryMemory::factory()->for($otherRepository)->create(['body' => 'Another repository memory.']);

    Learning::factory()->for($organization)->create(['body' => 'Org-wide: never flag missing docblocks.']);
    Learning::factory()->for($organization)->create(['repository_id' => $this->repository->id, 'body' => 'Repo: raw SQL in Billing is reviewed.']);
    Learning::factory()->for($organization)->create(['repository_id' => $otherRepository->id, 'body' => 'Other repository learning.']);
    Learning::factory()->for($organization)->proposed()->create(['body' => 'Not approved yet.']);

    CodeReviewer::fake([reviewerResponse([])]);

    runReview($this->run);

    CodeReviewer::assertPrompted(fn ($prompt) => $prompt->contains('<team_knowledge>')
        && $prompt->contains('Money is stored in cents.')
        && $prompt->contains('Billing talks to Stripe.')
        && ! $prompt->contains('Frontend uses Alpine.')
        && ! $prompt->contains('Another repository memory.')
        && $prompt->contains('Org-wide: never flag missing docblocks.')
        && $prompt->contains('Repo: raw SQL in Billing is reviewed.')
        && ! $prompt->contains('Other repository learning.')
        && ! $prompt->contains('Not approved yet.'));

    expect(RepositoryMemory::where('body', 'Billing talks to Stripe.')->sole())
        ->times_used->toBe(1)
        ->last_used_at->not->toBeNull();
});

it('stops a finding that matches a team learning and strengthens the learning', function () {
    config(['reviews.verifier.enabled' => true]);
    fakeGitHubForReview();
    $learning = Learning::factory()->for($this->repository->organization)->create([
        'body' => 'Interpolated SQL in Billing only uses typed ids from the session.',
        'weight' => 1,
    ]);

    CodeReviewer::fake([reviewerResponse([sqlInjectionFinding()])]);
    FindingVerifier::fake([['verdicts' => [[
        'id' => 0, 'verdict' => 'rejected', 'severity' => 'critical', 'confidence' => 90,
        'reason' => 'Matches the team learning about Billing SQL.', 'learning_id' => $learning->id,
    ]]]]);

    runReview($this->run);

    $finding = $this->run->findings()->sole();

    expect($finding->status)->toBe(FindingStatus::Rejected)
        ->and($finding->learning_id)->toBe($learning->id)
        ->and($learning->fresh())
        ->times_applied->toBe(1)
        ->weight->toBe(1.15);

    FindingVerifier::assertPrompted(fn ($prompt) => $prompt->contains("<team_learnings>\n- id {$learning->id}"));
});

it('ignores a learning id the verifier was not shown', function () {
    config(['reviews.verifier.enabled' => true]);
    fakeGitHubForReview();
    $elsewhere = Learning::factory()->create();

    CodeReviewer::fake([reviewerResponse([sqlInjectionFinding()])]);
    FindingVerifier::fake([['verdicts' => [[
        'id' => 0, 'verdict' => 'rejected', 'severity' => 'critical', 'confidence' => 90,
        'reason' => 'Looks fine.', 'learning_id' => $elsewhere->id,
    ]]]]);

    runReview($this->run);

    expect($this->run->findings()->sole()->learning_id)->toBeNull()
        ->and($elsewhere->fresh()->times_applied)->toBe(0);
});

it('records fixed and applied findings when their code changes', function () {
    $previousRun = ReviewRun::factory()->completed()->for($this->pullRequest)->create(['head_sha' => str_repeat('0', 40)]);
    $applied = ReviewFinding::factory()->for($previousRun, 'reviewRun')->create([
        'file_path' => 'app/Billing.php',
        'code' => 'return DB::select($raw);',
        'suggestion' => '        return DB::select("select * from invoices where user = $user");',
    ]);
    $fixed = ReviewFinding::factory()->for($previousRun, 'reviewRun')->create([
        'file_path' => 'app/Billing.php',
        'code' => 'echo $secret;',
        'suggestion' => null,
    ]);

    fakeGitHubForReview();
    CodeReviewer::fake([reviewerResponse([])]);

    runReview($this->run);

    expect($applied->signals()->sole()->type)->toBe(SignalType::Applied)
        ->and($fixed->signals()->sole()->type)->toBe(SignalType::Fixed);
});

it('learns from a dismissed false positive so the next similar pull request stays quiet', function () {
    Queue::fake([ProposeLearnings::class]);
    config(['reviews.verifier.enabled' => true]);
    fakeGitHubForReview();

    // 1. The reviewer flags interpolated SQL and a developer dismisses it.
    CodeReviewer::fake([reviewerResponse([sqlInjectionFinding()]), reviewerResponse([sqlInjectionFinding()])]);
    FindingVerifier::fake(fn (string $prompt) => ['verdicts' => [[
        'id' => 0,
        'verdict' => str_contains($prompt, '<team_learnings>') ? 'rejected' : 'confirmed',
        'severity' => 'critical',
        'confidence' => 90,
        'reason' => 'Checked.',
        'learning_id' => (int) Learning::where('status', LearningStatus::Active)->value('id'),
    ]]]);

    runReview($this->run);
    $dismissed = $this->run->findings()->sole();
    expect($dismissed->status)->toBe(FindingStatus::Published);

    $dismissed->update(['status' => FindingStatus::Dismissed, 'dismissed_by' => 'grace']);
    app(SignalRecorder::class)->record($dismissed, SignalType::Dismissed, 'grace');

    // 2. The feedback becomes a proposed learning, which an admin approves.
    LearningSynthesizer::fake([['learnings' => [[
        'kind' => 'false_positive', 'scope' => 'repository', 'category' => 'security', 'path_glob' => 'app/*',
        'body' => 'Interpolated SQL in Billing uses typed ids only; do not flag it as injection.',
        'rationale' => 'Dismissed by grace.', 'finding_ids' => [$dismissed->id], 'reinforces' => 0,
    ]]]]);
    (new ProposeLearnings($this->repository))->handle();

    $learning = Learning::sole();
    expect($learning->status)->toBe(LearningStatus::Proposed);
    $learning->update(['status' => LearningStatus::Active, 'reviewed_at' => now()]);

    // 3. A new pull request with the same pattern: the finding is stopped by the learning.
    $this->pullRequest->update(['number' => 6, 'state' => 'merged']);
    $next = PullRequest::factory()->for($this->repository)->create(['number' => 7, 'head_sha' => str_repeat('d', 40)]);
    $run = ReviewRun::factory()->for($next)->create(['head_sha' => str_repeat('d', 40)]);

    runReview($run);

    expect($run->findings()->sole())
        ->status->toBe(FindingStatus::Rejected)
        ->learning_id->toBe($learning->id);
    expect(collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/pulls/7/reviews') && $pair[0]['comments'] !== [])->count())->toBe(1);
});

it('embeds memories when an embeddings provider is configured', function () {
    config(['learning.embeddings.provider' => 'openai', 'learning.embeddings.dimensions' => 1536]);
    Embeddings::fake();

    $memory = RepositoryMemory::factory()->for($this->repository)->create(['body' => 'Money is stored in cents.']);

    expect($memory->fresh()->embedding)->toHaveCount(1536);
    Embeddings::assertGenerated(fn ($prompt) => $prompt->contains('Money is stored in cents.'));

    fakeGitHubForReview();
    CodeReviewer::fake([reviewerResponse([])]);

    runReview($this->run);

    CodeReviewer::assertPrompted(fn ($prompt) => $prompt->contains('Money is stored in cents.'));
});
