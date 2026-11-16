<?php

use App\Enums\ReviewTrigger;
use App\Jobs\BuildRepositoryGraph;
use App\Jobs\ProcessGitHubWebhook;
use App\Jobs\ReviewPullRequest;
use App\Models\GithubInstallation;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../GitHub/GitHubAppTestHelpers.php';

beforeEach(function () {
    Queue::fake([ReviewPullRequest::class]);
    configureGitHubApp();
    $this->installation = GithubInstallation::factory()->create();
    $this->repository = Repository::factory()->for($this->installation, 'installation')->create([
        'organization_id' => $this->installation->organization_id,
        'full_name' => 'acme/app',
    ]);

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'installation-token'], 201),
        'api.github.com/repos/acme/app/pulls/7' => Http::response(pullRequestPayload($this->repository)),
        'api.github.com/*' => Http::response([], 201),
    ]);
});

function pullRequestPayload(Repository $repository, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 9001,
        'number' => 7,
        'title' => 'Add billing',
        'body' => 'Adds billing',
        'state' => 'open',
        'draft' => false,
        'merged_at' => null,
        'html_url' => 'https://github.com/acme/app/pull/7',
        'user' => ['login' => 'ada'],
        'head' => ['sha' => str_repeat('a', 40), 'ref' => 'feature/billing'],
        'base' => ['sha' => str_repeat('b', 40), 'ref' => 'main'],
    ], $overrides);
}

function processEvent(string $event, ?string $action, GithubInstallation $installation, array $payload): WebhookEvent
{
    $webhookEvent = WebhookEvent::factory()->create([
        'event' => $event,
        'action' => $action,
        'installation_id' => $installation->installation_id,
        'payload' => $payload,
    ]);

    (new ProcessGitHubWebhook($webhookEvent))->handle();

    return $webhookEvent->fresh();
}

it('syncs the pull request and starts an automatic review when it is opened', function () {
    processEvent('pull_request', 'opened', $this->installation, [
        'action' => 'opened',
        'repository' => ['id' => $this->repository->github_id],
        'pull_request' => pullRequestPayload($this->repository),
        'sender' => ['login' => 'ada'],
    ]);

    $pullRequest = PullRequest::sole();
    $run = ReviewRun::sole();

    expect($pullRequest)
        ->number->toBe(7)
        ->head_sha->toBe(str_repeat('a', 40))
        ->and($run->trigger)->toBe(ReviewTrigger::Auto)
        ->and($run->head_sha)->toBe(str_repeat('a', 40));

    Queue::assertPushedOn('reviews', ReviewPullRequest::class);
});

it('does not review the same commit twice on redelivered events', function () {
    $payload = [
        'action' => 'synchronize',
        'repository' => ['id' => $this->repository->github_id],
        'pull_request' => pullRequestPayload($this->repository),
    ];

    processEvent('pull_request', 'synchronize', $this->installation, $payload);
    processEvent('pull_request', 'synchronize', $this->installation, $payload);

    expect(ReviewRun::count())->toBe(1);
    Queue::assertPushed(ReviewPullRequest::class, 1);
});

it('skips drafts and repositories with auto-review off', function (array $prOverrides, ?bool $autoReview) {
    $this->repository->update(['auto_review_enabled' => $autoReview]);

    processEvent('pull_request', 'opened', $this->installation, [
        'repository' => ['id' => $this->repository->github_id],
        'pull_request' => pullRequestPayload($this->repository, $prOverrides),
    ]);

    expect(PullRequest::count())->toBe(1)
        ->and(ReviewRun::count())->toBe(0);
})->with([
    'draft' => [['draft' => true], null],
    'auto-review disabled' => [[], false],
]);

it('marks merged pull requests', function () {
    processEvent('pull_request', 'closed', $this->installation, [
        'repository' => ['id' => $this->repository->github_id],
        'pull_request' => pullRequestPayload($this->repository, ['state' => 'closed', 'merged_at' => now()->toIso8601String()]),
    ]);

    expect(PullRequest::sole()->state)->toBe('merged')
        ->and(ReviewRun::count())->toBe(0);
});

function commentPayload(Repository $repository, string $body, string $association = 'MEMBER', string $senderType = 'User'): array
{
    return [
        'action' => 'created',
        'repository' => ['id' => $repository->github_id],
        'issue' => ['number' => 7, 'pull_request' => ['url' => 'https://api.github.com/repos/acme/app/pulls/7']],
        'comment' => ['id' => 555, 'body' => $body, 'author_association' => $association],
        'sender' => ['login' => 'grace', 'type' => $senderType],
    ];
}

it('starts a full review from a PR comment and reacts to it', function () {
    PullRequest::factory()->for($this->repository)->create(['number' => 7]);

    processEvent('issue_comment', 'created', $this->installation, commentPayload($this->repository, '@donkey full review'));

    $run = ReviewRun::sole();

    expect($run->trigger)->toBe(ReviewTrigger::Comment)
        ->and($run->is_full)->toBeTrue()
        ->and($run->triggered_by)->toBe('grace');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/repos/acme/app/issues/comments/555/reactions')
        && $request['content'] === 'eyes');
});

it('fetches the pull request when a comment arrives first', function () {
    processEvent('issue_comment', 'created', $this->installation, commentPayload($this->repository, '@donkey review'));

    expect(PullRequest::sole()->title)->toBe('Add billing')
        ->and(ReviewRun::count())->toBe(1);
});

it('ignores comments from untrusted authors and bots', function (string $association, string $senderType) {
    PullRequest::factory()->for($this->repository)->create(['number' => 7]);

    $event = processEvent('issue_comment', 'created', $this->installation, commentPayload($this->repository, '@donkey review', $association, $senderType));

    expect(ReviewRun::count())->toBe(0)
        ->and($event->status->value)->toBe('ignored');
})->with([
    'outside contributor' => ['CONTRIBUTOR', 'User'],
    'bot' => ['MEMBER', 'Bot'],
]);

it('replies with the list of commands when asked for help', function () {
    PullRequest::factory()->for($this->repository)->create(['number' => 7]);

    processEvent('issue_comment', 'created', $this->installation, commentPayload($this->repository, '@donkey help'));

    expect(ReviewRun::count())->toBe(0);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/repos/acme/app/issues/7/comments')
        && str_contains($request['body'], 'full review'));
});

it('pre-builds the base graph when a pull request is merged into the default branch', function () {
    Queue::fake([ReviewPullRequest::class, BuildRepositoryGraph::class]);

    processEvent('pull_request', 'closed', $this->installation, [
        'repository' => ['id' => $this->repository->github_id],
        'pull_request' => pullRequestPayload($this->repository, [
            'state' => 'closed',
            'merged_at' => now()->toIso8601String(),
            'merge_commit_sha' => str_repeat('m', 40),
        ]),
    ]);

    Queue::assertPushedOn('graphs', BuildRepositoryGraph::class, fn (BuildRepositoryGraph $job) => $job->commitSha === str_repeat('m', 40)
        && $job->repository->is($this->repository));
});

it('does not build graphs for pull requests closed without merging', function () {
    Queue::fake([ReviewPullRequest::class, BuildRepositoryGraph::class]);

    processEvent('pull_request', 'closed', $this->installation, [
        'repository' => ['id' => $this->repository->github_id],
        'pull_request' => pullRequestPayload($this->repository, ['state' => 'closed', 'merge_commit_sha' => str_repeat('m', 40)]),
    ]);

    Queue::assertNotPushed(BuildRepositoryGraph::class);
});
