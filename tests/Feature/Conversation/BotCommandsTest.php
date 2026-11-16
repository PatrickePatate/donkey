<?php

use App\Enums\ConversationRole;
use App\Enums\ConversationStatus;
use App\Enums\FindingStatus;
use App\Enums\ReviewTrigger;
use App\Jobs\AnswerPullRequestComment;
use App\Jobs\ReviewPullRequest;
use App\Models\ConversationMessage;
use App\Models\GithubInstallation;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\ReviewAgent;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/ConversationTestHelpers.php';

beforeEach(function () {
    Queue::fake([ReviewPullRequest::class, AnswerPullRequestComment::class]);
    configureGitHubApp();

    $this->installation = GithubInstallation::factory()->create();
    $this->repository = Repository::factory()->for($this->installation, 'installation')->create([
        'organization_id' => $this->installation->organization_id,
        'full_name' => 'acme/app',
    ]);
    $this->pullRequest = PullRequest::factory()->for($this->repository)->create(['number' => 7]);

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'installation-token'], 201),
        'api.github.com/*' => Http::response(['id' => 999], 201),
    ]);
});

it('stores a question and answers it in the background, once per comment', function () {
    deliverIssueComment($this->installation, $this->repository, '@donkey why does this need a migration?');
    deliverIssueComment($this->installation, $this->repository, '@donkey why does this need a migration?');

    $question = ConversationMessage::sole();

    expect($question)
        ->role->toBe(ConversationRole::User)
        ->status->toBe(ConversationStatus::Pending)
        ->author_login->toBe('grace')
        ->body->toBe('why does this need a migration?')
        ->thread_comment_id->toBeNull();

    Queue::assertPushed(AnswerPullRequestComment::class, 1);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/issues/comments/555/reactions') && $request['content'] === 'eyes');
});

it('links a question in a finding thread to the finding', function () {
    $finding = ReviewFinding::factory()
        ->for(ReviewRun::factory()->for($this->pullRequest), 'reviewRun')
        ->create(['github_comment_id' => 9001]);

    deliverThreadReply($this->installation, $this->pullRequest, 9001, '@donkey is this really exploitable? The id comes from the session.');

    expect(ConversationMessage::sole())
        ->review_finding_id->toBe($finding->id)
        ->thread_comment_id->toBe(9001)
        ->github_comment_id->toBe(556);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/pulls/comments/556/reactions'));
});

it('dismisses a finding from its thread', function () {
    $finding = ReviewFinding::factory()
        ->for(ReviewRun::factory()->for($this->pullRequest), 'reviewRun')
        ->create(['github_comment_id' => 9001]);

    deliverThreadReply($this->installation, $this->pullRequest, 9001, '@donkey false positive');

    expect($finding->fresh())
        ->status->toBe(FindingStatus::Dismissed)
        ->dismissed_by->toBe('grace');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/pulls/7/comments/9001/replies')
        && str_contains($request['body'], 'Dismissed'));
});

it('explains where to use ignore when it is not in a finding thread', function () {
    deliverIssueComment($this->installation, $this->repository, '@donkey ignore');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/issues/7/comments')
        && str_contains($request['body'], 'in the thread of one of my review comments'));
});

it('pauses and resumes automatic reviews on one pull request', function () {
    deliverIssueComment($this->installation, $this->repository, '@donkey pause');

    expect($this->pullRequest->fresh()->auto_review_paused)->toBeTrue();

    deliverConversationEvent('pull_request', $this->installation, [
        'action' => 'synchronize',
        'repository' => ['id' => $this->repository->github_id],
        'pull_request' => conversationPullRequestPayload($this->pullRequest),
        'sender' => ['login' => 'ada'],
    ]);

    expect(ReviewRun::count())->toBe(0);

    deliverIssueComment($this->installation, $this->repository, '@donkey resume', commentId: 557);

    expect($this->pullRequest->fresh()->auto_review_paused)->toBeFalse();
});

it('runs an agent requested in a comment', function () {
    $agent = ReviewAgent::factory()->create(['organization_id' => $this->repository->organization_id, 'slug' => 'security']);

    deliverIssueComment($this->installation, $this->repository, '@donkey run security');

    expect(ReviewRun::sole())
        ->review_agent_id->toBe($agent->id)
        ->trigger->toBe(ReviewTrigger::Comment)
        ->triggered_by->toBe('grace');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/issues/comments/555/reactions') && $request['content'] === 'rocket');
});

it('lists available agents when the requested one does not exist', function () {
    ReviewAgent::factory()->create([
        'organization_id' => $this->repository->organization_id,
        'slug' => 'laravel',
        'name' => 'Laravel',
        'description' => 'Framework conventions.',
    ]);

    deliverIssueComment($this->installation, $this->repository, '@donkey run perf');

    expect(ReviewRun::count())->toBe(0);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/issues/7/comments')
        && str_contains($request['body'], "I don't know an agent called `perf`")
        && str_contains($request['body'], '@donkey run laravel'));
});

it('lets past contributors list agents but not spend tokens', function () {
    deliverIssueComment($this->installation, $this->repository, '@donkey agents', 'CONTRIBUTOR');
    $question = deliverIssueComment($this->installation, $this->repository, '@donkey explain this change', 'CONTRIBUTOR', 558);

    expect($question->status->value)->toBe('ignored')
        ->and(ConversationMessage::count())->toBe(0);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/issues/7/comments')
        && str_contains($request['body'], 'No review agents are set up'));
});

it('ignores thread replies that do not mention the bot', function () {
    $event = deliverThreadReply($this->installation, $this->pullRequest, 9001, 'Good catch, fixing.');

    expect($event->status->value)->toBe('ignored')
        ->and(ConversationMessage::count())->toBe(0);
});
