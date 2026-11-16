<?php

use App\Enums\GraphStatus;
use App\Enums\RunGraphStatus;
use App\Models\GithubInstallation;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Services\Graph\GraphContextProvider;
use App\Services\Review\FilePatch;
use App\Services\Review\ReviewContext;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/../GitHub/GitHubAppTestHelpers.php';

const CONTEXT_JSON = [
    'graph' => ['nodes' => [], 'edges' => []],
    'analysis' => [
        'changed_functions' => [[
            'qualified_name' => 'src/auth.ts::AuthService.verifyToken',
            'name' => 'verifyToken',
            'file_path' => 'src/auth.ts',
            'line_start' => 16,
            'line_end' => 18,
            'callers' => [['qualified_name' => 'src/api.ts::login'], ['qualified_name' => 'src/api.ts::refresh']],
            'has_test_coverage' => false,
            'contract_diffs' => [['field' => 'params', 'old_value' => '(token: string)', 'new_value' => '(token: string, opts: VerifyOpts)']],
            'caller_impact' => '2 callers may need param update',
        ]],
        'blast_radius' => ['total_functions' => 5, 'total_files' => 3],
        'affected_flows' => [['entry_point' => 'POST /login']],
        'test_gaps' => [['function' => 'src/auth.ts::AuthService.verifyToken']],
        'risk' => ['level' => 'MEDIUM', 'score' => 0.57],
    ],
];

beforeEach(function () {
    configureGitHubApp();
    config([
        'graph.enabled' => true,
        'graph.binary' => 'kodus-graph',
        'graph.extra_path' => null,
        'graph.workspace_path' => storage_path('framework/testing/workspaces'),
        'graph.graph_path' => storage_path('framework/testing/graphs'),
    ]);
    Cache::forget('kodus-graph:available');
    Http::fake(['api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_secret_token'], 201)]);

    $installation = GithubInstallation::factory()->create();
    $this->repository = Repository::factory()->for($installation, 'installation')->create([
        'organization_id' => $installation->organization_id,
        'full_name' => 'acme/app',
    ]);
    $this->pullRequest = PullRequest::factory()->for($this->repository)->create([
        'base_sha' => str_repeat('b', 40),
        'head_sha' => str_repeat('a', 40),
    ]);
    $this->run = ReviewRun::factory()->for($this->pullRequest)->create(['head_sha' => str_repeat('a', 40)]);
    $this->context = new ReviewContext(str_repeat('b', 40), false, [
        'src/auth.ts' => FilePatch::fromGitHub('src/auth.ts', 'modified', "@@ -16,3 +16,3 @@\n-  verifyToken(token) {\n+  verifyToken(token, opts) {\n     return true;\n   }"),
    ], [], []);
});

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/workspaces'));
    File::deleteDirectory(storage_path('framework/testing/graphs'));
});

/**
 * Fake git and kodus-graph, writing the files the real CLI would produce.
 */
function fakeGraphProcesses(bool $available = true): void
{
    Process::fake(function (PendingProcess $process) use ($available) {
        $command = $process->command;

        if ($command[0] === 'kodus-graph') {
            if (! $available) {
                return Process::result(errorOutput: 'command not found', exitCode: 127);
            }

            $out = $command[array_search('--out', $command, true) + 1] ?? null;

            match ($command[1]) {
                'parse' => File::put($out, json_encode(['metadata' => ['total_nodes' => 120, 'total_edges' => 340, 'files_parsed' => 18], 'nodes' => [], 'edges' => []])),
                'update' => File::put($out, json_encode(['metadata' => ['total_nodes' => 121, 'total_edges' => 341, 'files_parsed' => 18], 'nodes' => [], 'edges' => []])),
                'context' => File::put($out, in_array('json', $command, true) ? json_encode(CONTEXT_JSON) : "1 changed (1 untested) | 5 impacted | 3 files | risk MEDIUM 0.57\nCHANGED:\n  verifyToken …"),
                default => null,
            };

            return Process::result('0.3.3');
        }

        if ($command[0] === 'git' && $command[1] === 'init') {
            File::ensureDirectoryExists($process->path.'/.git');
        }

        return Process::result();
    });
}

it('builds the base graph, checks out the head and returns structured context', function () {
    fakeGraphProcesses();

    $result = app(GraphContextProvider::class)->forReview($this->run, $this->context);

    expect($result->status)->toBe(RunGraphStatus::Ready)
        ->and($result->context->riskLevel)->toBe('MEDIUM')
        ->and($result->context->riskScore)->toBe(57)
        ->and($result->context->testGaps)->toBe(1)
        ->and($result->context->impactedFunctions)->toBe(5)
        ->and($result->context->prompt)->toContain('risk MEDIUM 0.57')
        ->and($result->context->changedFunctions[0]->callers)->toBe(2)
        ->and($result->context->summary()['changed_functions'][0]['contract'][0]['new'])->toBe('(token: string, opts: VerifyOpts)');

    $graph = $this->repository->graphs()->sole();
    expect($graph)
        ->status->toBe(GraphStatus::Ready)
        ->commit_sha->toBe(str_repeat('b', 40))
        ->nodes_count->toBe(120)
        ->is_incremental->toBeFalse();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['git', 'fetch', '--quiet', '--depth=1', '--no-tags', '--no-recurse-submodules', 'origin', str_repeat('b', 40)]);
    Process::assertRan(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'fetch' && end($process->command) === str_repeat('a', 40));
    Process::assertRan(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'context'
        && in_array('src/auth.ts', $process->command, true)
        && in_array($graph->path, $process->command, true));
});

it('never passes changed file names that look like flags to kodus-graph', function () {
    fakeGraphProcesses();
    $patch = "@@ -1 +1 @@\n-a\n+b";
    $context = new ReviewContext(str_repeat('b', 40), false, [
        'src/auth.ts' => FilePatch::fromGitHub('src/auth.ts', 'modified', $patch),
        '--out' => FilePatch::fromGitHub('--out', 'added', $patch),
    ], [], []);

    app(GraphContextProvider::class)->forReview($this->run, $context);

    Process::assertRan(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'context' && in_array('src/auth.ts', $process->command, true));
    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'context' && count(array_keys($process->command, '--out', true)) > 1);
});

it('authenticates git through the environment, never through arguments', function () {
    fakeGraphProcesses();

    app(GraphContextProvider::class)->forReview($this->run, $this->context);

    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'git'
        && $process->environment['GIT_CONFIG_VALUE_0'] === 'AUTHORIZATION: basic '.base64_encode('x-access-token:ghs_secret_token')
        && $process->environment['GIT_CONFIG_VALUE_1'] === '/dev/null');
    Process::assertDidntRun(fn (PendingProcess $process) => str_contains(implode(' ', (array) $process->command), 'ghs_secret_token'));
});

it('updates the latest graph incrementally for a new base commit', function () {
    fakeGraphProcesses();
    app(GraphContextProvider::class)->forReview($this->run, $this->context);

    $this->pullRequest->update(['base_sha' => str_repeat('c', 40)]);
    app(GraphContextProvider::class)->forReview($this->run->fresh(), $this->context);

    $latest = $this->repository->graphs()->where('commit_sha', str_repeat('c', 40))->sole();

    expect($latest->is_incremental)->toBeTrue()
        ->and($latest->nodes_count)->toBe(121);
    Process::assertRanTimes(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'parse', 1);
    Process::assertRanTimes(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'update', 1);
});

it('reuses a ready graph for the same base commit', function () {
    fakeGraphProcesses();
    app(GraphContextProvider::class)->forReview($this->run, $this->context);
    app(GraphContextProvider::class)->forReview($this->run, $this->context);

    Process::assertRanTimes(fn (PendingProcess $process) => in_array($process->command[1] ?? null, ['parse', 'update'], true), 1);
});

it('reports unavailable when kodus-graph is not installed', function () {
    fakeGraphProcesses(available: false);

    $result = app(GraphContextProvider::class)->forReview($this->run, $this->context);

    expect($result->status)->toBe(RunGraphStatus::Unavailable)
        ->and($result->context)->toBeNull();
    Process::assertDidntRun(fn (PendingProcess $process) => $process->command[0] === 'git');
});

it('degrades gracefully and records a failed graph when parsing fails', function () {
    Process::fake(function (PendingProcess $process) {
        if ($process->command[0] === 'kodus-graph' && $process->command[1] === 'parse') {
            return Process::result(errorOutput: 'out of memory', exitCode: 1);
        }

        if ($process->command[0] === 'git' && $process->command[1] === 'init') {
            File::ensureDirectoryExists($process->path.'/.git');
        }

        return Process::result('0.3.3');
    });

    $result = app(GraphContextProvider::class)->forReview($this->run, $this->context);

    expect($result->status)->toBe(RunGraphStatus::Unavailable)
        ->and($result->error)->toContain('out of memory')
        ->and($this->repository->graphs()->sole()->status)->toBe(GraphStatus::Failed);
});

it('does nothing when the graph is disabled', function () {
    config(['graph.enabled' => false]);
    Process::fake();

    expect(app(GraphContextProvider::class)->forReview($this->run, $this->context)->status)->toBe(RunGraphStatus::Disabled);
    Process::assertNothingRan();
});
