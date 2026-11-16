<?php

use App\Ai\Agents\CodeReviewer;
use App\Ai\Agents\PullRequestAssistant;
use App\Enums\AiFeature;
use App\Enums\ConversationStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewRunStatus;
use App\Jobs\AnswerPullRequestComment;
use App\Mail\BudgetThresholdReached;
use App\Models\AiUsage;
use App\Models\AuditEvent;
use App\Models\ConversationMessage;
use App\Services\Usage\UsageMeter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Ai\Responses\Data\Usage;
use Livewire\Livewire;

require_once __DIR__.'/../Reviews/ReviewTestHelpers.php';

beforeEach(fn () => setUpReviewFixture($this));

it('records the tokens and cost of every review call', function () {
    fakeGitHubForReview();
    CodeReviewer::fake([reviewerResponse([])]);

    runReview($this->run);

    $usage = AiUsage::sole();

    expect($usage)
        ->organization_id->toBe($this->repository->organization_id)
        ->repository_id->toBe($this->repository->id)
        ->pull_request_id->toBe($this->pullRequest->id)
        ->feature->toBe(AiFeature::Review)
        ->model->toBe('claude-sonnet-5-5')
        ->and($usage->cost_micros)->toBe(app(UsageMeter::class)->costMicros('claude-sonnet-5-5', $usage->input_tokens, $usage->output_tokens));
});

it('prices tokens from the pricing config', function () {
    $meter = app(UsageMeter::class);

    expect($meter->costMicros('claude-sonnet-5-5', 1_000_000, 100_000))->toBe(3_000_000)
        ->and($meter->costMicros('claude-opus-5-5', 10_000, 1_000))->toBe(60_000)
        ->and($meter->costMicros('some-unknown-model', 10_000, 1_000))->toBe(0);
});

it('stops reviewing once the monthly budget is spent', function () {
    fakeGitHubForReview();
    CodeReviewer::fake()->preventStrayPrompts();
    $organization = $this->repository->organization;
    $organization->update(['monthly_budget_cents' => 1_000]);
    AiUsage::factory()->for($organization)->create(['cost_micros' => 10_000_000]);
    AiUsage::factory()->for($organization)->create(['cost_micros' => 50_000_000, 'created_at' => now()->subMonthNoOverflow()]);

    runReview($this->run);

    expect($this->run->fresh())
        ->status->toBe(ReviewRunStatus::Skipped)
        ->summary->toContain('monthly AI budget');
    CodeReviewer::assertNeverPrompted();
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/check-runs/321') && $request['output']['title'] === 'Monthly AI budget reached');
});

it('declines to answer questions once the budget is spent', function () {
    fakeGitHubForReview(['api.github.com/repos/acme/app/issues/7/comments' => Http::response(['id' => 1], 201)]);
    PullRequestAssistant::fake()->preventStrayPrompts();
    $this->repository->organization->update(['monthly_budget_cents' => 0]);
    $question = ConversationMessage::factory()->for($this->pullRequest)->create(['status' => ConversationStatus::Pending]);

    app()->call([new AnswerPullRequestComment($question), 'handle']);

    expect($question->fresh()->status)->toBe(ConversationStatus::Failed);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/issues/7/comments') && str_contains($request['body'], 'monthly AI budget'));
});

it('emails owners once when spend crosses 80% and 100% of the budget', function () {
    Mail::fake();
    [$owner, $organization] = userInOrganization(OrganizationRole::Owner);
    [$admin] = userInOrganization();
    $organization->addMember($admin, OrganizationRole::Admin);
    $organization->update(['monthly_budget_cents' => 100]);
    $meter = app(UsageMeter::class);

    $meter->record($organization, AiFeature::Review, 'anthropic', 'claude-sonnet-5-5', new Usage(300_000, 0));
    Mail::assertNothingQueued();

    $meter->record($organization, AiFeature::Review, 'anthropic', 'claude-sonnet-5-5', new Usage(100_000, 0));
    $meter->record($organization, AiFeature::Review, 'anthropic', 'claude-sonnet-5-5', new Usage(10_000, 0));
    Mail::assertQueued(BudgetThresholdReached::class, 1);
    Mail::assertQueued(BudgetThresholdReached::class, fn ($mail) => $mail->hasTo($owner->email) && $mail->threshold === 0.8);

    $meter->record($organization, AiFeature::Review, 'anthropic', 'claude-sonnet-5-5', new Usage(100_000, 0));
    Mail::assertQueued(BudgetThresholdReached::class, 2);
    Mail::assertNotQueued(BudgetThresholdReached::class, fn ($mail) => $mail->hasTo($admin->email));
});

it('shows usage and cost to owners only', function () {
    [$owner, $organization] = userInOrganization(OrganizationRole::Owner);
    [$admin] = userInOrganization();
    $organization->addMember($admin, OrganizationRole::Admin);
    $admin->switchOrganization($organization);
    AiUsage::factory()->for($organization)->create(['cost_micros' => 1_250_000, 'feature' => AiFeature::Conversation]);
    AiUsage::factory()->for($organization)->create(['cost_micros' => 9_000_000, 'created_at' => now()->subMonthNoOverflow()->startOfMonth()]);

    $this->actingAs($owner)
        ->get(route('settings.usage'))
        ->assertOk()
        ->assertSee('$1.25')
        ->assertSee('Answers on GitHub')
        ->assertSee('$9.00 the month before');

    $this->actingAs($admin)->get(route('settings.usage'))->assertForbidden();
    $this->actingAs($admin)->get(route('dashboard'))->assertDontSee('Usage &amp; cost', false);
});

it('lets owners set the monthly budget, and audits it', function () {
    [$owner, $organization] = userInOrganization(OrganizationRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::settings.usage')
        ->set('budget', '250.50')
        ->call('saveBudget')
        ->assertHasNoErrors();

    expect($organization->fresh()->monthly_budget_cents)->toBe(25_050)
        ->and(AuditEvent::sole())
        ->action->toBe('organization.budget_changed')
        ->user_id->toBe($owner->id)
        ->metadata->toBe(['monthly_budget_cents' => [null, 25_050]]);
});
