<?php

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Services\Graph\ChangedFunction;
use App\Services\Graph\GraphContext;
use App\Services\Review\ProposedFinding;
use Tests\TestCase;

uses(TestCase::class);

function changedFunction(array $overrides = []): ChangedFunction
{
    return ChangedFunction::fromKodusGraph(array_merge([
        'qualified_name' => 'src/auth.ts::AuthService.verifyToken',
        'name' => 'verifyToken',
        'file_path' => 'src/auth.ts',
        'line_start' => 16,
        'line_end' => 30,
        'callers' => [],
        'has_test_coverage' => true,
        'contract_diffs' => [],
    ], $overrides));
}

function graphContext(array $functions): GraphContext
{
    return new GraphContext('prompt', 'MEDIUM', 45, $functions, 0, 0, 0, 0);
}

it('parses changed functions from kodus-graph output', function () {
    $function = changedFunction([
        'callers' => [['qualified_name' => 'a'], ['qualified_name' => 'b']],
        'contract_diffs' => [['field' => 'params', 'old_value' => '(a)', 'new_value' => '(a, b)']],
        'caller_impact' => '2 callers may need param update',
    ]);

    expect($function->callers)->toBe(2)
        ->and($function->contractChanges)->toBe([['field' => 'params', 'old' => '(a)', 'new' => '(a, b)']])
        ->and($function->callerImpact)->toBe('2 callers may need param update');
});

it('finds the innermost changed function at a line', function () {
    $outer = changedFunction(['qualified_name' => 'outer', 'line_start' => 10, 'line_end' => 50]);
    $inner = changedFunction(['qualified_name' => 'inner', 'line_start' => 20, 'line_end' => 25]);
    $graph = graphContext([$outer, $inner]);

    expect($graph->functionAt('src/auth.ts', 22)?->qualifiedName)->toBe('inner')
        ->and($graph->functionAt('src/auth.ts', 40)?->qualifiedName)->toBe('outer')
        ->and($graph->functionAt('src/other.ts', 22))->toBeNull()
        ->and($graph->functionAt('src/auth.ts', 5))->toBeNull();
});

it('boosts risk with callers, log-scaled and capped', function () {
    config(['reviews.max_impact_boost' => 0.3]);
    $graph = graphContext([]);
    $callers = fn (int $count) => array_fill(0, $count, ['qualified_name' => 'x']);

    $none = $graph->impactBoost(changedFunction());
    $one = $graph->impactBoost(changedFunction(['callers' => $callers(1)]));
    $many = $graph->impactBoost(changedFunction(['callers' => $callers(16)]));
    $contract = $graph->impactBoost(changedFunction(['callers' => $callers(1), 'contract_diffs' => [['field' => 'params']]]));
    $huge = $graph->impactBoost(changedFunction(['callers' => $callers(500), 'contract_diffs' => [['field' => 'params']]]));

    expect($none)->toBe(0.0)
        ->and($one)->toBeGreaterThan(0.0)->toBeLessThan($many)
        ->and($many)->toBe(0.3)
        ->and($contract)->toBeGreaterThan($one)
        ->and($huge)->toBe(0.3)
        ->and($graph->impactBoost(null))->toBe(0.0);
});

it('applies the impact boost to the risk score, capped at 100', function () {
    $finding = new ProposedFinding('src/auth.ts', 20, 20, FindingCategory::Bug, FindingSeverity::High, 80, 'Title', 'Body', null, 'code');

    expect($finding->riskScore())->toBe(60)
        ->and($finding->withImpact('sym', 4, 0.3)->riskScore())->toBe(78)
        ->and($finding->withAssessment(FindingSeverity::Critical, 100)->withImpact('sym', 9, 0.3)->riskScore())->toBe(100)
        ->and($finding->withImpact('sym', 4, 0.3)->fingerprint())->toBe($finding->fingerprint());
});
