<?php

use App\Ai\Agents\CodeReviewer;
use App\Enums\ReviewRunStatus;
use App\Jobs\ReviewPullRequest;
use App\Models\WebhookEvent;
use App\Services\GitHub\GitHubApp;
use App\Support\TransientFailure;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

require_once __DIR__.'/../Reviews/ReviewTestHelpers.php';

/**
 * @param  array<string, string>  $headers
 */
function requestException(int $status, array $headers = [], string $body = ''): RequestException
{
    return new RequestException(new Response(new Psr7Response($status, $headers, $body)));
}

it('retries a GitHub secondary rate limit and then succeeds', function () {
    Sleep::fake();
    configureGitHubApp();
    Http::fake([
        'api.github.com/app/installations/42' => Http::sequence()
            ->push(['message' => 'You have exceeded a secondary rate limit.'], 403, ['Retry-After' => '1'])
            ->push(['id' => 42, 'account' => ['login' => 'acme']]),
    ]);

    expect(app(GitHubApp::class)->installation(42))->toBe(['id' => 42, 'account' => ['login' => 'acme']]);

    Http::assertSentCount(2);
    Sleep::assertSequence([Sleep::for(1)->second()]);
});

it('recognizes GitHub rate limits', function (int $status, array $headers, string $body, bool $limited) {
    expect(GitHubApp::isRateLimited(new Response(new Psr7Response($status, $headers, $body))))->toBe($limited);
})->with([
    '429' => [429, [], '', true],
    'primary limit exhausted' => [403, ['X-RateLimit-Remaining' => '0'], '', true],
    'retry-after' => [403, ['Retry-After' => '60'], '', true],
    'secondary limit body' => [403, [], '{"message":"You have exceeded a secondary rate limit"}', true],
    'plain forbidden' => [403, ['X-RateLimit-Remaining' => '4999'], '{"message":"Resource not accessible"}', false],
    'not found' => [404, [], '', false],
]);

it('requeues the review when the AI provider rate-limits', function () {
    setUpReviewFixture($this);
    fakeGitHubForReview();
    CodeReviewer::fake(fn () => throw RateLimitedException::forProvider('anthropic'));

    $job = (new ReviewPullRequest($this->run))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased();
    expect($this->run->fresh())
        ->status->toBe(ReviewRunStatus::Queued)
        ->error->toBeNull();
});

it('backs off only for load failures', function () {
    expect(TransientFailure::backoff(RateLimitedException::forProvider('anthropic'), 1))->toBe(30)
        ->and(TransientFailure::backoff(ProviderOverloadedException::forProvider('anthropic'), 3))->toBe(120)
        ->and(TransientFailure::backoff(requestException(503), 1))->toBe(30)
        ->and(TransientFailure::backoff(requestException(429, ['Retry-After' => '300']), 1))->toBe(300)
        ->and(TransientFailure::backoff(requestException(403, [], 'secondary rate limit'), 10))->toBe(900)
        ->and(TransientFailure::backoff(requestException(404), 1))->toBeNull()
        ->and(TransientFailure::backoff(requestException(422), 1))->toBeNull()
        ->and(TransientFailure::backoff(new RuntimeException('Boom'), 1))->toBeNull();
});

it('prunes webhook events past the retention period', function () {
    config(['retention.webhook_event_days' => 30]);
    $old = WebhookEvent::factory()->create(['created_at' => now()->subDays(31)]);
    $recent = WebhookEvent::factory()->create(['created_at' => now()->subDays(29)]);

    $this->artisan('model:prune', ['--model' => [WebhookEvent::class]])->assertSuccessful();

    expect(WebhookEvent::find($old->id))->toBeNull()
        ->and(WebhookEvent::find($recent->id))->not->toBeNull();
});
