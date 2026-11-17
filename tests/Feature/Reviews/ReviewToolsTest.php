<?php

use App\Ai\Agents\CodeReviewer;
use App\Ai\Tools\ReadFile;
use App\Enums\GraphStatus;
use App\Models\GithubInstallation;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\RepositoryGraph;
use App\Models\ReviewRun;
use App\Services\Review\CodeExplorer;
use App\Services\Review\ReviewContextBuilder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;

require_once __DIR__.'/ReviewTestHelpers.php';

beforeEach(function () {
    config(['graph.workspace_path' => storage_path('framework/testing/workspaces'), 'graph.git_binary' => 'git']);

    $installation = GithubInstallation::factory()->create();
    $this->repository = Repository::factory()->for($installation, 'installation')->create(['organization_id' => $installation->organization_id]);
    $this->run = ReviewRun::factory()->for(PullRequest::factory()->for($this->repository))->create();
});

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/workspaces'));
    File::deleteDirectory(storage_path('framework/testing/graphs'));
});

/**
 * A real git workspace for the repository with one commit; returns its sha.
 */
function toolsWorkspace(Repository $repository): string
{
    $directory = storage_path('framework/testing/workspaces/'.$repository->id);
    File::ensureDirectoryExists($directory.'/app');
    File::put($directory.'/app/Billing.php', implode("\n", array_map(fn (int $line): string => "line {$line}", range(1, 400)))."\n");
    File::put($directory.'/app/Order.php', "<?php\n\$billing->charge(\$order);\n");

    $git = fn (string ...$arguments) => Process::path($directory)->run(['git', '-c', 'user.name=t', '-c', 'user.email=t@example.test', ...$arguments])->throw();
    $git('init', '--quiet');
    $git('add', '.');
    $git('commit', '--quiet', '-m', 'init');

    return trim($git('rev-parse', 'HEAD')->output());
}

function toolsGraph(Repository $repository): RepositoryGraph
{
    $node = fn (string $file, string $name, ?string $parent = null): array => [
        'kind' => $parent ? 'Method' : 'Class', 'name' => $name, 'file_path' => $file, 'line_start' => 3, 'parent_name' => $parent,
        'qualified_name' => $parent ? "{$file}::{$parent}.{$name}" : "{$file}::{$name}",
    ];
    $path = storage_path("framework/testing/graphs/{$repository->id}.json");
    File::ensureDirectoryExists(dirname($path));
    File::put($path, json_encode([
        'nodes' => [$node('app/Billing.php', 'charge', 'Billing'), $node('app/Order.php', 'place', 'Order'), $node('app/Refund.php', 'charge', 'Refund')],
        'edges' => [['kind' => 'CALLS', 'source_qualified' => 'app/Order.php::Order.place', 'target_qualified' => 'app/Billing.php::Billing.charge']],
    ]));

    return $repository->graphs()->create(['commit_sha' => str_repeat('b', 40), 'status' => GraphStatus::Ready, 'path' => $path, 'built_at' => now()]);
}

function codeExplorer(object $test, string $sha, ?RepositoryGraph $graph = null): CodeExplorer
{
    return app(CodeExplorer::class, [
        'repository' => $test->repository,
        'headSha' => $sha,
        'graph' => $graph,
        'log' => fn (string $message) => $test->run->log($message, 'tool'),
    ]);
}

it('reads a numbered line range of a file at the head commit and logs the call', function () {
    $explorer = codeExplorer($this, toolsWorkspace($this->repository));

    $result = $explorer->readFile('./app/Billing.php', 10, 12);

    expect($result)->toStartWith('app/Billing.php lines 10-12 of 400 (call again with start_line for more)')
        ->toContain('   11  line 11')
        ->not->toContain('line 13')
        ->and($explorer->readFile('app/Missing.php'))->toBe('No file at app/Missing.php in the pull request head.')
        ->and($this->run->logs()->pluck('message', 'level')->all())->toBe(['tool' => 'read_file app/Missing.php → not found'])
        ->and($this->run->logs()->first()->toolCall())->toBe(['tool' => 'read_file', 'arguments' => 'app/Billing.php:10-12', 'outcome' => '3 of 400 lines']);
});

it('caps a file read at 300 lines', function () {
    expect(codeExplorer($this, toolsWorkspace($this->repository))->readFile('app/Billing.php'))
        ->toStartWith('app/Billing.php lines 1-300 of 400');
});

it('searches the head commit for an exact text', function () {
    $explorer = codeExplorer($this, toolsWorkspace($this->repository));

    expect($explorer->search('->charge('))->toBe("1 match (path:line:content)\napp/Order.php:2:\$billing->charge(\$order);")
        ->and($explorer->search('->charge(', 'app/Billing.php'))->toStartWith('No line contains')
        ->and($explorer->search('ab'))->toBe('Search for at least 3 characters.');
});

it('answers callers of a symbol from the base graph and lists candidates when the name is ambiguous', function () {
    $explorer = codeExplorer($this, toolsWorkspace($this->repository), toolsGraph($this->repository));

    expect($explorer->symbol('Billing.charge'))
        ->toContain('app/Billing.php::Billing.charge (Method) at app/Billing.php:3')
        ->toContain("Callers and users:\n  - app/Order.php::Order.place app/Order.php:3 [CALLS]")
        ->and($explorer->symbol('charge'))->toStartWith('Several symbols match charge')
        ->toContain('app/Refund.php::Refund.charge')
        ->and($explorer->symbol('Invoice.send'))->toStartWith('No symbol named Invoice.send')
        ->and($this->run->logs()->pluck('message')->all())->toBe([
            'symbol_graph Billing.charge → 1 caller, 0 dependencies',
            'symbol_graph charge → 2 candidates',
            'symbol_graph Invoice.send → not found',
        ]);
});

it('offers the graph tool only when a base graph exists', function () {
    $sha = toolsWorkspace($this->repository);

    expect(array_map(fn ($tool) => $tool->name(), codeExplorer($this, $sha)->tools()))->toBe(['read_file', 'search_code'])
        ->and(array_map(fn ($tool) => $tool->name(), codeExplorer($this, $sha, toolsGraph($this->repository))->tools()))->toBe(['read_file', 'search_code', 'symbol_graph']);
});

it('bounds tool use and asks for the final answer on the last step', function () {
    config(['reviews.tools.max_steps' => 6]);
    $reviewer = CodeReviewer::make()->withCodeTools([new ReadFile(codeExplorer($this, 'sha'))]);
    $step = fn (int $number, bool $final) => new PendingStep($number, $final, 'anthropic', 'model', null, [new UserMessage('diff')], [], null, null);
    $sent = null;
    $next = function (PendingStep $step) use (&$sent) {
        $sent = $step;

        return Mockery::mock(StepResult::class);
    };

    $reviewer->middleware()[0]($step(5, true), $next);
    $lastMessages = $sent->messages;
    $reviewer->middleware()[0]($step(3, false), $next);

    expect($reviewer->maxSteps())->toBe(6)
        ->and(CodeReviewer::make()->maxSteps())->toBeNull()
        ->and($lastMessages)->toHaveCount(2)
        ->and(end($lastMessages)->content)->toContain('Give your final answer now')
        ->and($sent->messages)->toHaveCount(1)
        ->and($reviewer->instructions())->toContain('at most 5 rounds of tool calls')
        ->and(CodeReviewer::make()->instructions())->not->toContain('## Tools');
});

it('asks Anthropic to cache the conversation only for agents with tools', function () {
    $tools = [new ReadFile(codeExplorer($this, 'sha'))];

    expect(CodeReviewer::make()->withCodeTools($tools)->providerOptions('anthropic'))->toBe(['cache_control' => ['type' => 'ephemeral']])
        ->and(CodeReviewer::make()->withCodeTools($tools)->withEffort('high')->providerOptions('openai'))->toBe(['reasoning' => ['effort' => 'high']])
        ->and(CodeReviewer::make()->providerOptions('anthropic'))->toBe([]);
});

it('keeps removed files apart from the reviewed ones', function () {
    setUpReviewFixture($this);
    // Registered first so it wins over the helper's default file list.
    Http::fake(['api.github.com/repos/acme/app/pulls/7/files*' => Http::response([
        ['filename' => 'app/Billing.php', 'status' => 'modified', 'patch' => BILLING_PATCH],
        ['filename' => 'app/Legacy.php', 'status' => 'removed', 'patch' => "@@ -1 +0,0 @@\n-<?php"],
        ['filename' => 'yarn.lock', 'status' => 'removed', 'patch' => '@@ -1 +0,0 @@'],
    ])]);
    fakeGitHubForReview();

    $context = app(ReviewContextBuilder::class)->build($this->run);

    expect(array_keys($context->files))->toBe(['app/Billing.php'])
        ->and($context->removedFiles)->toBe(['app/Legacy.php']);
});
