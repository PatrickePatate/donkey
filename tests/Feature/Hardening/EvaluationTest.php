<?php

use App\Ai\Agents\CodeReviewer;
use App\Models\AiUsage;
use Illuminate\Support\Facades\File;

it('scores the reviewer against a seeded case without billing anyone', function () {
    CodeReviewer::fake([[
        'summary' => 'Adds an invoice search endpoint.',
        'risk_score' => 90,
        'findings' => [[
            'file' => 'app/Http/Controllers/InvoiceSearchController.php',
            'start_line' => 14,
            'end_line' => 14,
            'category' => 'security',
            'severity' => 'critical',
            'confidence' => 95,
            'title' => 'SQL injection in the customer search',
            'explanation' => 'The customer name is interpolated into raw SQL.',
            'suggestion' => null,
        ]],
    ]]);
    $path = tempnam(sys_get_temp_dir(), 'eval').'.json';

    $this->artisan('eval:run', ['--case' => ['sql-injection'], '--force' => true, '--json' => $path])
        ->expectsOutputToContain('Recall 100% · precision 100% · precision of posted findings 100% · severity within one level 100%')
        ->assertSuccessful();

    $results = json_decode(File::get($path), true);
    File::delete($path);

    expect($results['cases'])->toHaveKeys(['sql-injection'])->toHaveCount(1)
        ->and($results['cases']['sql-injection'])->toMatchArray(['expected' => 1, 'found' => 1, 'findings' => 1, 'correct_findings' => 1, 'missed' => [], 'unexpected' => []])
        ->and($results['totals'])->toMatchArray(['recall' => 1, 'precision' => 1, 'severity_accuracy' => 1])
        ->and(AiUsage::count())->toBe(0);

    CodeReviewer::assertPromptedTimes(1);
});
