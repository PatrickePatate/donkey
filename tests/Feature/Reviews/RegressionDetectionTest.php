<?php

use App\Ai\Agents\CodeReviewer;
use App\Ai\Agents\FindingVerifier;
use App\Enums\FindingCategory;
use App\Enums\FindingStatus;
use App\Enums\FindingVerification;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/ReviewTestHelpers.php';

beforeEach(function () {
    setUpReviewFixture($this);
    config(['reviews.verifier.enabled' => true, 'graph.enabled' => false]);
    fakeGitHubForReview();
});

function mediumRegression(array $overrides = []): array
{
    return sqlInjectionFinding([
        'category' => 'regression',
        'severity' => 'medium',
        'confidence' => 80,
        'title' => 'Inverted trial check',
        'suggestion' => '',
        ...$overrides,
    ]);
}

it('publishes a medium regression below the threshold once the verifier confirms it', function () {
    CodeReviewer::fake([reviewerResponse([mediumRegression()])]);
    FindingVerifier::fake([['verdicts' => [
        ['id' => 0, 'verdict' => 'confirmed', 'severity' => 'medium', 'confidence' => 85, 'reason' => 'Old code returned true for future dates.'],
    ]]]);

    runReview($this->run);

    // medium (45) × 85% = 38, below the 50 threshold.
    expect($this->run->findings()->sole())
        ->risk_score->toBe(38)
        ->category->toBe(FindingCategory::Regression)
        ->verification->toBe(FindingVerification::Confirmed)
        ->status->toBe(FindingStatus::Published);
});

it('keeps gated findings suppressed when they are rejected, skipped or too weak', function () {
    CodeReviewer::fake([reviewerResponse([
        mediumRegression(),
        mediumRegression(['start_line' => 5, 'end_line' => 5, 'title' => 'Unverified', 'category' => 'logic']),
        mediumRegression(['start_line' => 6, 'end_line' => 6, 'title' => 'Low confidence', 'confidence' => 50]),
        mediumRegression(['start_line' => 7, 'end_line' => 7, 'title' => 'Maintainability', 'category' => 'maintainability']),
    ])]);
    FindingVerifier::fake([['verdicts' => [
        ['id' => 0, 'verdict' => 'rejected', 'severity' => 'low', 'confidence' => 10, 'reason' => 'Intended change.'],
    ]]]);

    runReview($this->run);

    $findings = $this->run->findings()->get()->keyBy('title');

    expect($findings['Inverted trial check']->status)->toBe(FindingStatus::Rejected)
        ->and($findings['Unverified']->status)->toBe(FindingStatus::Suppressed)
        ->and($findings['Unverified']->verification)->toBe(FindingVerification::Skipped)
        ->and($findings['Low confidence']->status)->toBe(FindingStatus::Suppressed)
        ->and($findings['Low confidence']->verification)->toBeNull()
        ->and($findings['Maintainability']->verification)->toBeNull();
});

it('does not bypass the threshold without the verifier', function () {
    config(['reviews.verifier.enabled' => false]);
    CodeReviewer::fake([reviewerResponse([mediumRegression()])]);

    runReview($this->run);

    expect($this->run->findings()->sole()->status)->toBe(FindingStatus::Suppressed);
});

it('does not bypass the threshold when the gate is disabled', function () {
    config(['reviews.correctness_gate.enabled' => false]);
    CodeReviewer::fake([reviewerResponse([mediumRegression()])]);
    FindingVerifier::fake([['verdicts' => []]]);

    runReview($this->run);

    expect($this->run->findings()->sole())
        ->status->toBe(FindingStatus::Suppressed)
        ->verification->toBeNull();
});

it('asks the reviewer to look for regressions', function () {
    expect((new CodeReviewer)->instructions())
        ->toContain('Use the "regression"')
        ->toContain('backward compatibility');
});

it('ships well-formed regression evaluation cases', function () {
    $cases = collect(File::glob(base_path('tests/Evals/cases/*.json')))
        ->map(fn (string $path): array => json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR));

    $regressions = $cases->filter(fn (array $case): bool => str_starts_with($case['name'], 'regression-'));

    expect($regressions->count())->toBeGreaterThanOrEqual(20);

    $cases->each(function (array $case): void {
        $paths = array_column($case['files'], 'path');

        foreach ($case['expected'] as $expectation) {
            expect($paths)->toContain($expectation['file'])
                ->and(FindingCategory::tryFrom($expectation['category']))->not->toBeNull();
        }
    });
});
