<?php

use App\Ai\Agents\CodeReviewer;
use App\Enums\FindingStatus;
use App\Enums\ReviewRunStatus;
use App\Models\AiUsage;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/ReviewTestHelpers.php';

beforeEach(fn () => setUpReviewFixture($this));

it('publishes findings above the threshold as inline comments with suggestions', function () {
    fakeGitHubForReview();
    CodeReviewer::fake([reviewerResponse([
        sqlInjectionFinding(),
        sqlInjectionFinding([
            'start_line' => 5, 'end_line' => 5, 'category' => 'maintainability', 'severity' => 'low',
            'confidence' => 60, 'title' => 'Unused $amount parameter', 'suggestion' => '',
        ]),
        sqlInjectionFinding(['start_line' => 40, 'end_line' => 40, 'title' => 'Points at a line outside the diff']),
        sqlInjectionFinding(['file' => 'app/Unknown.php', 'title' => 'Points at a file outside the diff']),
    ])]);

    runReview($this->run);

    $run = $this->run->fresh();
    $findings = $run->findings()->get()->keyBy('title');

    expect($run->status)->toBe(ReviewRunStatus::Completed)
        ->and($run->files_reviewed)->toBe(1)
        ->and($run->risk_score)->toBe(70)
        ->and($run->risk_threshold)->toBe(50)
        ->and($run->github_review_id)->toBe(777)
        ->and($findings)->toHaveCount(2)
        ->and($findings['SQL injection through string interpolation'])
        ->status->toBe(FindingStatus::Published)
        ->risk_score->toBe(90)
        ->github_comment_id->toBe(9001)
        ->and($findings['Unused $amount parameter'])
        ->status->toBe(FindingStatus::Suppressed)
        ->and($this->pullRequest->fresh()->last_reviewed_sha)->toBe(str_repeat('a', 40))
        ->and(AiUsage::pluck('review_run_id')->unique()->all())->toBe([$run->id]);

    CodeReviewer::assertPrompted(fn ($prompt) => $prompt->contains('Always use parameter binding for SQL.')
        && $prompt->contains('    7 +         return DB::select')
        && ! $prompt->contains('composer.lock'));

    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), '/pulls/7/reviews') || $request->method() !== 'POST') {
            return false;
        }

        $comments = $request['comments'];

        return $request['commit_id'] === str_repeat('a', 40)
            && count($comments) === 1
            && $comments[0]['path'] === 'app/Billing.php'
            && $comments[0]['line'] === 7
            && str_contains($comments[0]['body'], '```suggestion');
    });

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/issues/comments/555')
        && str_contains($request['body'], '1 lower-risk finding below the threshold (50) is kept in the dashboard'));

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/check-runs/321')
        && $request['conclusion'] === 'neutral');
});

it('does not repost an issue already published on the pull request', function () {
    fakeGitHubForReview([
        'api.github.com/repos/acme/app/compare/*' => Http::response([
            'status' => 'ahead',
            'files' => [['filename' => 'app/Billing.php']],
        ]),
    ]);
    CodeReviewer::fake([reviewerResponse([sqlInjectionFinding()]), reviewerResponse([sqlInjectionFinding(['start_line' => 7, 'end_line' => 7, 'title' => 'Reworded by the model'])])]);

    runReview($this->run);

    $this->pullRequest->update(['head_sha' => str_repeat('c', 40)]);
    $second = ReviewRun::factory()->for($this->pullRequest)->create(['head_sha' => str_repeat('c', 40)]);

    runReview($second);

    expect($second->fresh()->findings()->sole()->status)->toBe(FindingStatus::Duplicate)
        ->and(ReviewFinding::where('status', FindingStatus::Published)->count())->toBe(1);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/compare/'.str_repeat('a', 40).'...'.str_repeat('c', 40)));
    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/pulls/7/reviews'))->count())->toBe(1);
});

it('marks earlier findings outdated once their code is gone', function () {
    fakeGitHubForReview();
    $previousRun = ReviewRun::factory()->completed()->for($this->pullRequest)->create(['head_sha' => str_repeat('0', 40)]);
    $outdated = ReviewFinding::factory()->for($previousRun)->create([
        'pull_request_id' => $this->pullRequest->id,
        'file_path' => 'app/Billing.php',
        'code' => '    public function charge($user) {}',
        'status' => FindingStatus::Published,
    ]);
    $stillValid = ReviewFinding::factory()->for($previousRun)->create([
        'pull_request_id' => $this->pullRequest->id,
        'file_path' => 'app/Billing.php',
        'code' => 'class Billing',
        'status' => FindingStatus::Published,
    ]);
    CodeReviewer::fake([reviewerResponse([])]);

    runReview($this->run);

    expect($outdated->fresh()->status)->toBe(FindingStatus::Outdated)
        ->and($stillValid->fresh()->status)->toBe(FindingStatus::Published);
});

it('falls back to a summary-only review when GitHub rejects inline positions', function () {
    Http::fake([
        'api.github.com/repos/acme/app/pulls/7/reviews' => Http::sequence()
            ->push(['message' => 'Unprocessable Entity', 'errors' => ['Line could not be resolved']], 422)
            ->push(['id' => 778]),
    ]);
    fakeGitHubForReview();
    CodeReviewer::fake([reviewerResponse([sqlInjectionFinding()])]);

    runReview($this->run);

    expect($this->run->fresh())
        ->status->toBe(ReviewRunStatus::Completed)
        ->github_review_id->toBe(778);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/pulls/7/reviews')
        && $request['comments'] === []
        && str_contains($request['body'], '`app/Billing.php` L7'));
});

it('stops when newer commits have been pushed', function () {
    fakeGitHubForReview();
    CodeReviewer::fake()->preventStrayPrompts();
    $this->pullRequest->update(['head_sha' => str_repeat('d', 40)]);

    runReview($this->run);

    expect($this->run->fresh()->status)->toBe(ReviewRunStatus::Superseded);
    CodeReviewer::assertNeverPrompted();
    Http::assertNothingSent();
});

it('records failures without throwing and closes the check run', function () {
    Http::fake([
        'api.github.com/repos/acme/app/pulls/7/files*' => Http::response(['message' => 'Server Error'], 500),
    ]);
    fakeGitHubForReview();
    CodeReviewer::fake()->preventStrayPrompts();

    runReview($this->run);

    $run = $this->run->fresh();

    expect($run->status)->toBe(ReviewRunStatus::Failed)
        ->and($run->error)->not->toBeEmpty()
        ->and($run->stage?->value)->toBe('collecting');

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/check-runs/321')
        && $request['output']['title'] === 'Review failed');
});

it('skips pushes with nothing reviewable', function () {
    Http::fake([
        'api.github.com/repos/acme/app/pulls/7/files*' => Http::response([
            ['filename' => 'package-lock.json', 'status' => 'modified', 'patch' => '@@ -1 +1 @@'],
            ['filename' => 'logo.png', 'status' => 'added'],
        ]),
    ]);
    fakeGitHubForReview();
    CodeReviewer::fake()->preventStrayPrompts();

    runReview($this->run);

    expect($this->run->fresh()->status)->toBe(ReviewRunStatus::Skipped);
    CodeReviewer::assertNeverPrompted();
});
