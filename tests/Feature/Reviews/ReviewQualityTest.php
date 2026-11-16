<?php

use App\Ai\Agents\CodeReviewer;
use App\Ai\Agents\FindingVerifier;
use App\Enums\FindingStatus;
use App\Enums\FindingVerification;
use App\Enums\ReviewStage;
use App\Enums\RunGraphStatus;
use App\Services\Graph\ChangedFunction;
use App\Services\Graph\GraphContext;
use App\Services\Graph\GraphContextProvider;
use App\Services\Graph\GraphContextResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/ReviewTestHelpers.php';

beforeEach(function () {
    setUpReviewFixture($this);
    config(['reviews.max_impact_boost' => 0.3]);
});

function fakeGraph(?GraphContext $context, RunGraphStatus $status = RunGraphStatus::Ready): void
{
    test()->mock(GraphContextProvider::class)
        ->shouldReceive('forReview')
        ->andReturn(new GraphContextResult($status, $context));
}

function billingGraph(int $callers = 16): GraphContext
{
    return new GraphContext(
        prompt: "1 changed (1 untested) | 9 impacted | 4 files | risk HIGH 0.81\nCHANGED:\n  charge(user, amount) [app/Billing.php:6-9] | {$callers} callers",
        riskLevel: 'HIGH',
        riskScore: 81,
        changedFunctions: [ChangedFunction::fromKodusGraph([
            'qualified_name' => 'app/Billing.php::Billing.charge',
            'name' => 'charge',
            'file_path' => 'app/Billing.php',
            'line_start' => 6,
            'line_end' => 9,
            'callers' => array_fill(0, $callers, ['qualified_name' => 'x']),
            'has_test_coverage' => false,
            'contract_diffs' => [['field' => 'params', 'old_value' => '($user)', 'new_value' => '($user, $amount)']],
        ])],
        testGaps: 1,
        impactedFunctions: 9,
        impactedFiles: 4,
        affectedFlows: 2,
    );
}

it('gives the model graph context and boosts findings in heavily used code', function () {
    fakeGitHubForReview();
    fakeGraph(billingGraph());
    CodeReviewer::fake([reviewerResponse([sqlInjectionFinding(['severity' => 'high', 'confidence' => 60])])]);

    runReview($this->run);

    $run = $this->run->fresh();
    $finding = $run->findings()->sole();

    // high (75) × 60% = 45 → below the 50 threshold without the graph; ×1.3 = 59 with it.
    expect($finding)
        ->risk_score->toBe(59)
        ->status->toBe(FindingStatus::Published)
        ->graph_symbol->toBe('app/Billing.php::Billing.charge')
        ->graph_callers->toBe(16)
        ->and($run->graph_status)->toBe(RunGraphStatus::Ready)
        ->and($run->graph_risk_level)->toBe('HIGH')
        ->and($run->graph_summary['test_gaps'])->toBe(1);

    CodeReviewer::assertPrompted(fn ($prompt) => $prompt->contains('<code_graph>') && $prompt->contains('risk HIGH 0.81'));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/pulls/7/reviews')
        && str_contains($request['comments'][0]['body'], 'used by 16 callers')
        && str_contains($request['body'], '**Impact** · 1 changed function · 9 impacted in 4 files · 1 without tests · structural risk HIGH'));
});

it('reviews without graph context when the graph is unavailable', function () {
    fakeGitHubForReview();
    fakeGraph(null, RunGraphStatus::Unavailable);
    CodeReviewer::fake([reviewerResponse([sqlInjectionFinding()])]);

    runReview($this->run);

    expect($this->run->fresh()->graph_status)->toBe(RunGraphStatus::Unavailable)
        ->and($this->run->findings()->sole()->status)->toBe(FindingStatus::Published);
    CodeReviewer::assertPrompted(fn ($prompt) => ! $prompt->contains('<code_graph>'));
});

it('lets the verifier reject false positives and downgrade overstated findings', function () {
    config(['reviews.verifier.enabled' => true]);
    fakeGitHubForReview([
        'api.github.com/repos/acme/app/contents/app/Billing.php*' => Http::response("<?php\n\nclass Billing\n{\n    public function charge(\$user, \$amount)\n    {\n        return DB::select(\"select * from invoices where user = \$user\");\n    }\n}\n"),
    ]);
    fakeGraph(null, RunGraphStatus::Disabled);
    CodeReviewer::fake([reviewerResponse([
        sqlInjectionFinding(),
        sqlInjectionFinding(['start_line' => 5, 'end_line' => 5, 'category' => 'bug', 'title' => 'Amount may be negative', 'suggestion' => '']),
        sqlInjectionFinding(['start_line' => 6, 'end_line' => 6, 'category' => 'logic', 'title' => 'Imaginary race condition', 'suggestion' => '']),
    ])]);
    FindingVerifier::fake([[
        'verdicts' => [
            ['id' => 0, 'verdict' => 'confirmed', 'severity' => 'critical', 'confidence' => 95, 'reason' => 'User input reaches raw SQL.'],
            ['id' => 1, 'verdict' => 'confirmed', 'severity' => 'low', 'confidence' => 40, 'reason' => 'Plausible but minor.'],
            ['id' => 2, 'verdict' => 'rejected', 'severity' => 'low', 'confidence' => 5, 'reason' => 'No shared state is touched.'],
        ],
    ]]);

    runReview($this->run);

    $findings = $this->run->findings()->get()->keyBy('title');

    expect($findings['SQL injection through string interpolation'])
        ->status->toBe(FindingStatus::Published)
        ->verification->toBe(FindingVerification::Confirmed)
        ->confidence->toBe(95)
        ->and($findings['Amount may be negative'])
        ->status->toBe(FindingStatus::Suppressed)
        ->severity->value->toBe('low')
        ->and($findings['Imaginary race condition'])
        ->status->toBe(FindingStatus::Rejected)
        ->verifier_note->toBe('No shared state is touched.')
        ->and($this->run->fresh()->findings_rejected)->toBe(1);

    FindingVerifier::assertPrompted(fn ($prompt) => $prompt->contains('<finding id="2">')
        && $prompt->contains('    7 >         return DB::select'));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/pulls/7/reviews') && count($request['comments']) === 1);
});

it('publishes unverified findings when the verifier call fails', function () {
    config(['reviews.verifier.enabled' => true]);
    fakeGitHubForReview();
    fakeGraph(null, RunGraphStatus::Disabled);
    CodeReviewer::fake([reviewerResponse([sqlInjectionFinding()])]);
    FindingVerifier::fake(fn () => throw new RuntimeException('Provider overloaded'));

    runReview($this->run);

    expect($this->run->findings()->sole())
        ->status->toBe(FindingStatus::Published)
        ->verification->toBe(FindingVerification::Skipped);
});

it('shows the map impact step in the stepper', function () {
    expect(array_column(ReviewStage::steps(), 'label'))->toBe(['Collect', 'Map impact', 'Analyze', 'Validate', 'Publish']);
});
