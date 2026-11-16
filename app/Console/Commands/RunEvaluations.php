<?php

namespace App\Console\Commands;

use App\Enums\FindingSeverity;
use App\Models\Organization;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Services\Review\FilePatch;
use App\Services\Review\ProposedFinding;
use App\Services\Review\ReviewAnalyzer;
use App\Services\Review\ReviewContext;
use App\Services\Usage\UsageMeter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Runs the reviewer on pull requests with known, seeded problems and measures how many it finds (recall), how much
 * of what it says is right (precision) and whether it judged severity correctly. Run it before changing a prompt or
 * a model, and compare.
 */
#[Signature('eval:run
    {--case=* : Only run these cases (file names without .json)}
    {--model= : Model to evaluate instead of config("reviews.model")}
    {--threshold=50 : Risk score at which a finding would be posted on GitHub}
    {--json= : Also write the results to this file}
    {--force : Do not ask before calling the model}')]
#[Description('Measure the reviewer\'s recall, precision and severity accuracy on seeded pull requests')]
class RunEvaluations extends Command
{
    /**
     * Expected and actual line ranges may be this far apart and still match.
     */
    private const LINE_TOLERANCE = 3;

    public function handle(ReviewAnalyzer $analyzer, UsageMeter $usage): int
    {
        $cases = $this->cases();

        if ($cases === []) {
            $this->error('No evaluation cases found in tests/Evals/cases.');

            return self::FAILURE;
        }

        $model = (string) ($this->option('model') ?: config('reviews.model'));
        config(['reviews.model' => $model]);

        if (! $this->option('force') && ! $this->confirm('This calls '.$model.' for '.count($cases).' '.str('case')->plural(count($cases)).' and costs real money. Continue?', true)) {
            return self::SUCCESS;
        }

        $threshold = (int) $this->option('threshold');
        $results = [];

        foreach ($cases as $name => $case) {
            try {
                $analysis = $analyzer->analyze($this->pullRequest($case), $this->reviewContext($case));
            } catch (Throwable $exception) {
                $this->error("{$name}: {$exception->getMessage()}");

                return self::FAILURE;
            }

            $results[$name] = $this->score($case['expected'], $analysis->findings, $threshold)
                + ['cost_micros' => $usage->costMicros($model, $analysis->inputTokens, $analysis->outputTokens)];
        }

        $this->report($results, $model, $threshold);

        if ($path = $this->option('json')) {
            File::put($path, json_encode(['model' => $model, 'threshold' => $threshold, 'cases' => $results, 'totals' => $this->totals($results)], JSON_PRETTY_PRINT));
            $this->info("Results written to {$path}.");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{title: string, description: string, files: list<array{path: string, status: string, patch: string}>, expected: list<array{file: string, start_line: int, end_line: int, category: string, severity: string}>}>
     */
    private function cases(): array
    {
        $only = $this->option('case');

        return collect(File::glob(base_path('tests/Evals/cases/*.json')))
            ->mapWithKeys(fn (string $path): array => [pathinfo($path, PATHINFO_FILENAME) => json_decode(File::get($path), true)])
            ->when($only !== [], fn ($cases) => $cases->only($only))
            ->sortKeys()
            ->all();
    }

    /**
     * An unsaved pull request: nothing is stored and no organization is billed.
     *
     * @param  array{title: string, description: string}  $case
     */
    private function pullRequest(array $case): PullRequest
    {
        $repository = (new Repository(['full_name' => 'eval/fixture']))->setRelation('organization', new Organization(['name' => 'Evaluation']));

        return (new PullRequest([
            'title' => $case['title'],
            'body' => $case['description'],
            'head_ref' => 'feature/eval',
            'base_ref' => 'main',
            'state' => 'open',
        ]))->setRelation('repository', $repository);
    }

    /**
     * @param  array{files: list<array{path: string, status: string, patch: string}>}  $case
     */
    private function reviewContext(array $case): ReviewContext
    {
        $files = [];

        foreach ($case['files'] as $file) {
            $files[$file['path']] = FilePatch::fromGitHub($file['path'], $file['status'], $file['patch']);
        }

        return new ReviewContext(baseSha: str_repeat('0', 40), isIncremental: false, files: $files, instructions: [], skippedFiles: []);
    }

    /**
     * @param  list<array{file: string, start_line: int, end_line: int, category: string, severity: string}>  $expected
     * @param  list<ProposedFinding>  $findings
     * @return array{expected: int, found: int, findings: int, correct_findings: int, posted: int, correct_posted: int, severity_matches: int, missed: list<string>, unexpected: list<string>}
     */
    private function score(array $expected, array $findings, int $threshold): array
    {
        $matchedExpectations = [];
        $correct = [];
        $severityMatches = 0;

        foreach ($findings as $index => $finding) {
            foreach ($expected as $key => $expectation) {
                if (! $this->matches($expectation, $finding)) {
                    continue;
                }

                $correct[$index] = true;

                if (! isset($matchedExpectations[$key])) {
                    $matchedExpectations[$key] = true;
                    $severityMatches += abs($this->rank($finding->severity) - $this->rank(FindingSeverity::from($expectation['severity']))) <= 1 ? 1 : 0;
                }
            }
        }

        $posted = array_filter($findings, fn (ProposedFinding $finding): bool => $finding->riskScore() >= $threshold || $finding->passesCorrectnessGate());

        return [
            'expected' => count($expected),
            'found' => count($matchedExpectations),
            'findings' => count($findings),
            'correct_findings' => count($correct),
            'posted' => count($posted),
            'correct_posted' => count(array_intersect_key($correct, $posted)),
            'severity_matches' => $severityMatches,
            'missed' => array_values(array_map(
                fn (array $expectation): string => "{$expectation['category']} at {$expectation['file']}:{$expectation['start_line']}",
                array_diff_key($expected, $matchedExpectations),
            )),
            'unexpected' => array_values(array_map(
                fn (ProposedFinding $finding): string => "{$finding->severity->value} {$finding->category->value} at {$finding->path}:{$finding->startLine} — {$finding->title}",
                array_diff_key($findings, $correct),
            )),
        ];
    }

    /**
     * Same file and overlapping lines (with some slack). The category is not required to match: a real problem
     * reported under a neighbouring category still counts as found.
     *
     * @param  array{file: string, start_line: int, end_line: int}  $expectation
     */
    private function matches(array $expectation, ProposedFinding $finding): bool
    {
        return $finding->path === $expectation['file']
            && $finding->startLine <= $expectation['end_line'] + self::LINE_TOLERANCE
            && $finding->endLine >= $expectation['start_line'] - self::LINE_TOLERANCE;
    }

    private function rank(FindingSeverity $severity): int
    {
        return array_search($severity, FindingSeverity::cases(), true);
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     * @return array{recall: ?float, precision: ?float, posted_precision: ?float, severity_accuracy: ?float, cost_micros: int}
     */
    private function totals(array $results): array
    {
        $sum = fn (string $key): int => (int) array_sum(array_column($results, $key));
        $ratio = fn (int $part, int $whole): ?float => $whole > 0 ? round($part / $whole, 3) : null;

        return [
            'recall' => $ratio($sum('found'), $sum('expected')),
            'precision' => $ratio($sum('correct_findings'), $sum('findings')),
            'posted_precision' => $ratio($sum('correct_posted'), $sum('posted')),
            'severity_accuracy' => $ratio($sum('severity_matches'), $sum('found')),
            'cost_micros' => $sum('cost_micros'),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     */
    private function report(array $results, string $model, int $threshold): void
    {
        $this->table(
            ['Case', 'Found', 'Findings', 'Correct', 'Posted ≥'.$threshold, 'Correct posted'],
            collect($results)->map(fn (array $result, string $name): array => [
                $name,
                "{$result['found']}/{$result['expected']}",
                $result['findings'],
                $result['correct_findings'],
                $result['posted'],
                $result['correct_posted'],
            ])->values()->all(),
        );

        foreach ($results as $name => $result) {
            foreach ($result['missed'] as $missed) {
                $this->line("  <fg=red>missed</> {$name}: {$missed}");
            }

            foreach ($result['unexpected'] as $unexpected) {
                $this->line("  <fg=yellow>unexpected</> {$name}: {$unexpected}");
            }
        }

        $totals = $this->totals($results);
        $percent = fn (?float $value): string => $value === null ? '—' : round($value * 100).'%';

        $this->newLine();
        $this->line("Model {$model}");
        $this->line('Recall '.$percent($totals['recall'])
            .' · precision '.$percent($totals['precision'])
            .' · precision of posted findings '.$percent($totals['posted_precision'])
            .' · severity within one level '.$percent($totals['severity_accuracy'])
            .' · cost $'.number_format($totals['cost_micros'] / 1_000_000, 4));
    }
}
