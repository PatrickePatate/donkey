<?php

use App\Enums\FindingStatus;
use App\Enums\SignalType;
use App\Jobs\AnswerPullRequestComment;
use App\Jobs\CollectFindingFeedback;
use App\Jobs\ProposeLearnings;
use App\Jobs\ReviewPullRequest;
use App\Models\ConversationMessage;
use App\Models\FindingSignal;
use App\Models\GithubInstallation;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\RepositoryMemory;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Conversation/ConversationTestHelpers.php';

beforeEach(function () {
    Queue::fake([ReviewPullRequest::class, AnswerPullRequestComment::class, ProposeLearnings::class, CollectFindingFeedback::class]);
    configureGitHubApp();

    $this->installation = GithubInstallation::factory()->create();
    $this->repository = Repository::factory()->for($this->installation, 'installation')->create([
        'organization_id' => $this->installation->organization_id,
        'full_name' => 'acme/app',
    ]);
    $this->pullRequest = PullRequest::factory()->for($this->repository)->create(['number' => 7]);
    $this->finding = ReviewFinding::factory()
        ->for(ReviewRun::factory()->for($this->pullRequest), 'reviewRun')
        ->create(['github_comment_id' => 9001, 'file_path' => 'app/Reports/Revenue.php']);

    Http::fake([
        'api.github.com/repos/acme/app/pulls/comments/9001' => Http::response(['id' => 9001, 'reactions' => ['+1' => 0, '-1' => 2]]),
        'api.github.com/repos/acme/app/pulls/comments/9002' => Http::response(['id' => 9002, 'reactions' => ['+1' => 1, '-1' => 0]]),
        'api.github.com/repos/acme/app/pulls/comments/9003' => Http::response([], 404),
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'installation-token'], 201),
        'api.github.com/*' => Http::response(['id' => 999], 201),
    ]);
});

it('records a dismissal with its reason and asks for a learning proposal', function () {
    deliverThreadReply($this->installation, $this->pullRequest, 9001, '@donkey ignore: report queries only use constants');

    expect($this->finding->fresh()->status)->toBe(FindingStatus::Dismissed)
        ->and(FindingSignal::sole())
        ->type->toBe(SignalType::Dismissed)
        ->actor_login->toBe('grace')
        ->repository_id->toBe($this->repository->id)
        ->and(ConversationMessage::sole())
        ->review_finding_id->toBe($this->finding->id)
        ->body->toBe('Dismissed: report queries only use constants');

    Queue::assertPushed(ProposeLearnings::class, fn (ProposeLearnings $job) => $job->repository->is($this->repository) && $job->delay !== null);
});

it('remembers a fact from a finding thread for that file', function () {
    deliverThreadReply($this->installation, $this->pullRequest, 9001, '@donkey remember report queries are built from constants');
    deliverThreadReply($this->installation, $this->pullRequest, 9001, '@donkey remember report queries are built from constants');

    expect(RepositoryMemory::sole())
        ->repository_id->toBe($this->repository->id)
        ->body->toBe('report queries are built from constants')
        ->path_glob->toBe('app/Reports/Revenue.php')
        ->source->toBe(RepositoryMemory::SOURCE_GITHUB)
        ->author_login->toBe('grace');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/pulls/7/comments/9001/replies')
        && str_contains($request['body'], 'Noted'));
});

it('remembers a repository-wide fact from the pull request conversation', function () {
    deliverIssueComment($this->installation, $this->repository, '@donkey remember: we deploy with zero-downtime migrations only');

    expect(RepositoryMemory::sole())
        ->path_glob->toBeNull()
        ->body->toBe('we deploy with zero-downtime migrations only');
});

it('counts a resolved finding thread as positive feedback', function () {
    $event = deliverConversationEvent('pull_request_review_thread', $this->installation, [
        'action' => 'resolved',
        'thread' => ['comments' => [['id' => 9001], ['id' => 9002]]],
        'sender' => ['login' => 'ada'],
    ]);

    expect($event->status->value)->toBe('processed')
        ->and(FindingSignal::sole())
        ->type->toBe(SignalType::Resolved)
        ->actor_login->toBe('ada');
});

it('collects feedback when the pull request closes', function () {
    deliverConversationEvent('pull_request', $this->installation, [
        'action' => 'closed',
        'repository' => ['id' => $this->repository->github_id],
        'pull_request' => [...conversationPullRequestPayload($this->pullRequest), 'state' => 'closed', 'merged_at' => now()->toIso8601String()],
        'sender' => ['login' => 'ada'],
    ]);

    Queue::assertPushed(CollectFindingFeedback::class);
});

it('reads reactions and notes findings merged untouched', function () {
    $liked = ReviewFinding::factory()->for($this->finding->reviewRun, 'reviewRun')->create(['github_comment_id' => 9002]);
    $deleted = ReviewFinding::factory()->for($this->finding->reviewRun, 'reviewRun')->create(['github_comment_id' => 9003]);
    $this->pullRequest->update(['state' => 'merged']);

    app()->call([new CollectFindingFeedback($this->pullRequest), 'handle']);

    expect($this->finding->signals()->pluck('type')->all())->toEqualCanonicalizing([SignalType::ThumbsDown, SignalType::IgnoredOnMerge])
        ->and($liked->signals()->pluck('type')->all())->toBe([SignalType::ThumbsUp])
        ->and($deleted->signals()->pluck('type')->all())->toBe([SignalType::IgnoredOnMerge]);

    Queue::assertPushed(ProposeLearnings::class);
});
