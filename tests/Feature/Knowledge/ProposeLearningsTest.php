<?php

use App\Actions\DecayLearnings;
use App\Ai\Agents\LearningSynthesizer;
use App\Enums\FindingCategory;
use App\Enums\LearningKind;
use App\Enums\LearningStatus;
use App\Enums\SignalType;
use App\Jobs\ProposeLearnings;
use App\Models\ConversationMessage;
use App\Models\FindingSignal;
use App\Models\Learning;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->repository = Repository::factory()->create();
    $this->pullRequest = PullRequest::factory()->for($this->repository)->create();
    $this->run = ReviewRun::factory()->for($this->pullRequest)->create();
});

function dismissedFinding(object $test, array $attributes = [], SignalType $type = SignalType::Dismissed): ReviewFinding
{
    $finding = ReviewFinding::factory()->for($test->run, 'reviewRun')->create($attributes);
    FindingSignal::factory()->for($finding, 'finding')->create(['type' => $type, 'actor_login' => 'grace']);

    return $finding;
}

it('proposes learnings from dismissed findings and their explanations', function () {
    $first = dismissedFinding($this, ['title' => 'Raw SQL in revenue report', 'file_path' => 'app/Reports/Revenue.php']);
    $second = dismissedFinding($this, ['title' => 'Raw SQL in churn report', 'file_path' => 'app/Reports/Churn.php']);
    ConversationMessage::factory()->for($this->pullRequest)->create([
        'review_finding_id' => $first->id,
        'author_login' => 'grace',
        'body' => 'Dismissed: report queries only use constants',
    ]);

    LearningSynthesizer::fake([['learnings' => [
        [
            'kind' => 'false_positive', 'scope' => 'organization', 'category' => 'security', 'path_glob' => 'app/Reports/*',
            'body' => 'Raw SQL in app/Reports is built from constants; do not flag it.', 'rationale' => 'Two dismissals.',
            'finding_ids' => [$first->id, $second->id, 999999], 'reinforces' => 0,
        ],
        [
            'kind' => 'preference', 'scope' => 'repository', 'category' => '', 'path_glob' => '',
            'body' => 'Unsupported by any finding.', 'rationale' => '', 'finding_ids' => [], 'reinforces' => 0,
        ],
    ]]]);

    app()->call([new ProposeLearnings($this->repository), 'handle']);

    $learning = Learning::sole();

    expect($learning)
        ->status->toBe(LearningStatus::Proposed)
        ->kind->toBe(LearningKind::FalsePositive)
        ->repository_id->toBeNull()
        ->organization_id->toBe($this->repository->organization_id)
        ->category->toBe(FindingCategory::Security)
        ->path_glob->toBe('app/Reports/*')
        ->source_finding_ids->toBe([$first->id, $second->id])
        ->and(FindingSignal::whereNull('processed_at')->count())->toBe(0);

    LearningSynthesizer::assertPrompted(fn ($prompt) => $prompt->contains('Raw SQL in revenue report')
        && $prompt->contains('Dismissed by @grace')
        && $prompt->contains('@grace: Dismissed: report queries only use constants'));
});

it('reinforces an existing learning instead of duplicating it, and never revives rejected ones', function () {
    $finding = dismissedFinding($this);
    $active = Learning::factory()->for($this->repository->organization)->create(['weight' => 1, 'source_finding_ids' => [1]]);
    $rejected = Learning::factory()->for($this->repository->organization)->create(['status' => LearningStatus::Rejected]);

    LearningSynthesizer::fake([['learnings' => [
        ['kind' => 'false_positive', 'scope' => 'repository', 'category' => '', 'path_glob' => '', 'body' => 'Same as active.', 'rationale' => '', 'finding_ids' => [$finding->id], 'reinforces' => $active->id],
        ['kind' => 'false_positive', 'scope' => 'repository', 'category' => '', 'path_glob' => '', 'body' => 'Same as rejected.', 'rationale' => '', 'finding_ids' => [$finding->id], 'reinforces' => $rejected->id],
    ]]]);

    app()->call([new ProposeLearnings($this->repository), 'handle']);

    expect(Learning::count())->toBe(2)
        ->and($active->fresh())
        ->source_finding_ids->toBe([1, $finding->id])
        ->times_applied->toBe(1)
        ->and($rejected->fresh()->status)->toBe(LearningStatus::Rejected);

    LearningSynthesizer::assertPrompted(fn ($prompt) => $prompt->contains("id {$rejected->id} [rejected"));
});

it('waits for more evidence when findings were only merged untouched', function () {
    LearningSynthesizer::fake()->preventStrayPrompts();

    dismissedFinding($this, type: SignalType::IgnoredOnMerge);
    dismissedFinding($this, type: SignalType::IgnoredOnMerge);

    app()->call([new ProposeLearnings($this->repository), 'handle']);

    LearningSynthesizer::assertNeverPrompted();
    expect(FindingSignal::whereNull('processed_at')->count())->toBe(2);
});

it('decays idle learnings and archives the weakest', function () {
    $idle = Learning::factory()->create(['weight' => 1, 'last_applied_at' => now()->subDays(40)]);
    $weak = Learning::factory()->create(['weight' => 0.3, 'last_applied_at' => now()->subDays(40)]);
    $recent = Learning::factory()->create(['weight' => 1, 'last_applied_at' => now()->subDay()]);
    $neverMatched = Learning::factory()->create(['weight' => 1, 'reviewed_at' => now()->subDays(60)]);

    expect(app(DecayLearnings::class)->handle())->toBe(['decayed' => 3, 'archived' => 1]);

    expect($idle->fresh()->weight)->toBe(0.98)
        ->and($weak->fresh()->status)->toBe(LearningStatus::Archived)
        ->and($recent->fresh()->weight)->toBe(1.0)
        ->and($neverMatched->fresh()->weight)->toBe(0.98);
});

it('queues proposals for repositories with unprocessed negative feedback', function () {
    Queue::fake();
    dismissedFinding($this);
    $other = Repository::factory()->create();
    $positive = ReviewFinding::factory()->for(ReviewRun::factory()->for(PullRequest::factory()->for($other)), 'reviewRun')->create();
    FindingSignal::factory()->for($positive, 'finding')->create(['type' => SignalType::Applied]);

    $this->artisan('learnings:propose')->assertSuccessful();

    Queue::assertPushed(ProposeLearnings::class, 1);
    Queue::assertPushed(ProposeLearnings::class, fn ($job) => $job->repository->is($this->repository));
});
