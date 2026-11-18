<?php

use App\Ai\Agents\CodeReviewer;
use App\Models\ReviewRun;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/ReviewTestHelpers.php';

beforeEach(fn () => setUpReviewFixture($this));

it('keeps one comment per pull request, posted when the review starts and edited when it ends', function () {
    fakeGitHubForReview(['api.github.com/repos/acme/app/compare/*' => Http::response(['status' => 'ahead', 'files' => [['filename' => 'app/Billing.php']]])]);
    CodeReviewer::fake([reviewerResponse([sqlInjectionFinding()]), reviewerResponse([])]);

    runReview($this->run);

    $this->pullRequest->update(['head_sha' => str_repeat('c', 40)]);
    $second = ReviewRun::factory()->for($this->pullRequest)->create(['head_sha' => str_repeat('c', 40)]);
    runReview($second);

    $posts = collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/issues/7/comments'));
    $edits = collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'PATCH' && str_ends_with($pair[0]->url(), '/issues/comments/555'));

    expect($posts)->toHaveCount(1)
        ->and($posts->first()[0]['body'])->toContain('Reviewing `aaaaaaa`')
        ->and($edits)->toHaveCount(3)
        ->and($second->fresh()->github_comment_id)->toBe(555);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && str_ends_with($request->url(), '/issues/comments/555') && str_contains($request['body'], 'No issues above the risk threshold') && str_contains($request['body'], '**Diff** · 1 file · +'));
});

it('posts a new comment when the previous one was deleted', function () {
    $this->run->update(['github_comment_id' => 999]);
    fakeGitHubForReview(['api.github.com/repos/acme/app/issues/comments/999' => Http::response([], 404)]);
    CodeReviewer::fake([reviewerResponse([])]);

    runReview($this->run);

    expect($this->run->fresh()->github_comment_id)->toBe(555);
});

it('logs what the review is doing', function () {
    fakeGitHubForReview();
    CodeReviewer::fake([reviewerResponse([sqlInjectionFinding()])]);

    runReview($this->run);

    $messages = $this->run->logs()->orderBy('id')->pluck('message')->implode("\n");

    expect($messages)->toContain('1 file to review')
        ->toContain('Asking ')
        ->toContain('1 finding proposed');
});

it('links inline comments to findings even when GitHub omits the line', function () {
    fakeGitHubForReview(['api.github.com/repos/acme/app/pulls/7/reviews/777/comments*' => Http::response([['id' => 9001, 'path' => 'app/Billing.php', 'position' => 13]])]);
    CodeReviewer::fake([reviewerResponse([sqlInjectionFinding()])]);

    runReview($this->run);

    expect($this->run->fresh()->findings()->sole()->github_comment_id)->toBe(9001);
});
