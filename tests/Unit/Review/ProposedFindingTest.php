<?php

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Services\Review\ProposedFinding;

function proposedFinding(array $overrides = []): ProposedFinding
{
    return new ProposedFinding(...array_merge([
        'path' => 'app/Foo.php',
        'startLine' => 10,
        'endLine' => 10,
        'category' => FindingCategory::Bug,
        'severity' => FindingSeverity::High,
        'confidence' => 80,
        'title' => 'Null dereference',
        'body' => 'Explain',
        'suggestion' => null,
        'code' => '$user->name;',
    ], $overrides));
}

it('weights severity by confidence', function () {
    expect(proposedFinding()->riskScore())->toBe(60)
        ->and(proposedFinding(['severity' => FindingSeverity::Critical, 'confidence' => 100])->riskScore())->toBe(100)
        ->and(proposedFinding(['severity' => FindingSeverity::Low, 'confidence' => 50])->riskScore())->toBe(10);
});

it('fingerprints ignore line numbers, wording and whitespace', function () {
    $original = proposedFinding();
    $moved = proposedFinding(['startLine' => 42, 'endLine' => 42, 'title' => 'Possible null', 'code' => '  $user->name ;']);

    expect($moved->fingerprint())->toBe($original->fingerprint());
});

it('fingerprints differ by file, category and code', function () {
    $original = proposedFinding();

    expect(proposedFinding(['path' => 'app/Bar.php'])->fingerprint())->not->toBe($original->fingerprint())
        ->and(proposedFinding(['category' => FindingCategory::Security])->fingerprint())->not->toBe($original->fingerprint())
        ->and(proposedFinding(['code' => '$user->email;'])->fingerprint())->not->toBe($original->fingerprint());
});
