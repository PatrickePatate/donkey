<?php

use App\Ai\Agents\PullRequestAssistant;
use App\Enums\ConversationRole;
use App\Enums\ConversationStatus;
use App\Jobs\AnswerPullRequestComment;
use App\Models\ConversationMessage;
use App\Models\ReviewFinding;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Reviews/ReviewTestHelpers.php';

beforeEach(function () {
    setUpReviewFixture($this);
    fakeGitHubForReview([
        'api.github.com/repos/acme/app/pulls/7/comments/9001/replies' => Http::response(['id' => 7001], 201),
        'api.github.com/repos/acme/app/pulls/7/comments*' => Http::response([
            ['id' => 9001, 'path' => 'app/Billing.php', 'line' => 7, 'diff_hunk' => '@@ -5,1 +5,4 @@', 'body' => 'SQL injection', 'user' => ['login' => 'donkey[bot]']],
            ['id' => 556, 'in_reply_to_id' => 9001, 'body' => '@donkey is this exploitable?', 'user' => ['login' => 'grace']],
            ['id' => 600, 'body' => 'Unrelated thread', 'user' => ['login' => 'ada']],
        ]),
        'api.github.com/repos/acme/app/issues/7/comments' => Http::response(['id' => 7002], 201),
    ]);
});

function answer(ConversationMessage $question): void
{
    app()->call([new AnswerPullRequestComment($question), 'handle']);
}

it('answers in the finding thread with the finding, thread and diff as context', function () {
    $finding = ReviewFinding::factory()->for($this->run, 'reviewRun')->create([
        'title' => 'SQL injection through string interpolation',
        'file_path' => 'app/Billing.php',
        'github_comment_id' => 9001,
    ]);
    $question = ConversationMessage::factory()->for($this->pullRequest)->create([
        'review_finding_id' => $finding->id,
        'thread_comment_id' => 9001,
        'github_comment_id' => 556,
        'author_login' => 'grace',
        'body' => 'is this exploitable?',
        'status' => ConversationStatus::Pending,
    ]);

    PullRequestAssistant::fake(['Yes: `$user` comes from the request, so `1 or 1=1` returns every invoice.']);

    answer($question);

    PullRequestAssistant::assertPrompted(fn ($prompt) => $prompt->contains('<finding>')
        && $prompt->contains('SQL injection through string interpolation')
        && $prompt->contains('@grace: @donkey is this exploitable?')
        && ! $prompt->contains('Unrelated thread')
        && $prompt->contains('<file path="app/Billing.php"')
        && $prompt->contains('is this exploitable?'));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/pulls/7/comments/9001/replies')
        && str_contains($request['body'], 'returns every invoice'));

    $reply = ConversationMessage::where('role', ConversationRole::Bot)->sole();

    expect($question->fresh()->status)->toBe(ConversationStatus::Answered)
        ->and($reply)
        ->in_reply_to_id->toBe($question->id)
        ->github_comment_id->toBe(7001)
        ->review_finding_id->toBe($finding->id);
});

it('answers questions in the pull request conversation with earlier exchanges', function () {
    ConversationMessage::factory()->for($this->pullRequest)->create(['body' => 'what does this PR change?', 'author_login' => 'ada']);
    ConversationMessage::factory()->for($this->pullRequest)->fromBot()->create(['body' => 'It adds an amount to charge().', 'github_comment_id' => null]);
    $question = ConversationMessage::factory()->for($this->pullRequest)->create([
        'author_login' => 'grace',
        'body' => 'and is it backwards compatible?',
        'status' => ConversationStatus::Pending,
    ]);

    PullRequestAssistant::fake(['No, existing callers pass one argument.']);

    answer($question);

    PullRequestAssistant::assertPrompted(fn ($prompt) => $prompt->contains('<conversation>')
        && $prompt->contains('You: It adds an amount to charge().'));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/issues/7/comments')
        && $request['body'] === '@grace No, existing callers pass one argument.');
});

it('apologizes once when the model call fails', function () {
    $question = ConversationMessage::factory()->for($this->pullRequest)->create(['status' => ConversationStatus::Pending, 'author_login' => 'grace']);

    PullRequestAssistant::fake(fn () => throw new RuntimeException('Overloaded'));

    answer($question);
    answer($question);

    expect($question->fresh()->status)->toBe(ConversationStatus::Failed)
        ->and(ConversationMessage::where('role', ConversationRole::Bot)->sole()->status)->toBe(ConversationStatus::Failed);

    expect(collect(Http::recorded())->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/issues/7/comments'))->count())->toBe(1);
});
