<?php

use App\Ai\Agents\CodeReviewer;
use App\Ai\Agents\RepositoryDocumenter;
use App\Enums\AiFeature;
use App\Enums\OrganizationRole;
use App\Jobs\GenerateRepositoryDocumentation;
use App\Jobs\ProcessGitHubWebhook;
use App\Models\AiUsage;
use App\Models\AuditEvent;
use App\Models\GithubInstallation;
use App\Models\Repository;
use App\Models\WebhookEvent;
use App\Services\Review\ReviewAnalyzer;
use App\Services\Review\ReviewContextBuilder;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;

require_once __DIR__.'/../Reviews/ReviewTestHelpers.php';

function runRepositoryDocumentation(Repository $repository): void
{
    app()->call([new GenerateRepositoryDocumentation($repository), 'handle']);
}

/**
 * @param  list<array{path: string, type: string}>  $tree
 * @param  array<string, string>  $contents
 */
function fakeRepositoryDocumentationGitHub(Repository $repository, string $sha, array $tree, array $contents = []): void
{
    configureGitHubApp();
    Http::preventStrayRequests();

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'installation-token'], 201),
        "api.github.com/repos/{$repository->full_name}/commits/*" => Http::response(['sha' => $sha]),
        "api.github.com/repos/{$repository->full_name}/git/trees/*" => Http::response(['tree' => $tree]),
        "api.github.com/repos/{$repository->full_name}/contents/*" => function (Request $request) use ($contents, $repository) {
            $prefix = "/repos/{$repository->full_name}/contents/";
            $path = urldecode(substr((string) parse_url($request->url(), PHP_URL_PATH), strlen($prefix)));

            return Http::response($contents[$path] ?? "Contents for {$path}");
        },
    ]);
}

/**
 * @param  array<string, mixed>  $pullRequest
 */
function deliverMergedPullRequest(Repository $repository, array $pullRequest): void
{
    $event = WebhookEvent::factory()->create([
        'event' => 'pull_request',
        'action' => 'closed',
        'installation_id' => $repository->installation->installation_id,
        'payload' => [
            'repository' => ['id' => $repository->github_id],
            'pull_request' => [
                'id' => $pullRequest['id'],
                'number' => $pullRequest['number'],
                'title' => 'Documentation change',
                'body' => null,
                'user' => ['login' => 'developer'],
                'state' => 'closed',
                'draft' => false,
                'head' => ['sha' => str_repeat('a', 40), 'ref' => 'feature/docs'],
                'base' => ['sha' => str_repeat('b', 40), 'ref' => $pullRequest['base_ref']],
                'html_url' => 'https://github.com/acme/docs/pull/'.$pullRequest['number'],
                'merged_at' => now()->toIso8601String(),
                'merge_commit_sha' => str_repeat('c', 40),
            ],
        ],
    ]);

    (new ProcessGitHubWebhook($event))->handle();
}

it('generates documentation from the repository tree and selected source files', function () {
    $repository = Repository::factory()->create(['full_name' => 'acme/docs', 'auto_docs_enabled' => true]);
    $sha = str_repeat('d', 40);
    config([
        'documentation.key_files' => ['README.md'],
        'documentation.source_extensions' => ['php'],
    ]);
    fakeRepositoryDocumentationGitHub($repository, $sha, [
        ['path' => 'README.md', 'type' => 'blob'],
        ['path' => 'app/Services/PaymentService.php', 'type' => 'blob'],
        ['path' => 'docs/overview.md', 'type' => 'blob'],
        ['path' => 'app', 'type' => 'tree'],
    ], [
        'README.md' => 'Repository overview from the README.',
        'app/Services/PaymentService.php' => 'Payment service source details.',
        'docs/overview.md' => 'This Markdown source should not be sampled.',
    ]);
    RepositoryDocumenter::fake(['# Architecture'."\n\n".'A generated repository overview.']);

    runRepositoryDocumentation($repository);

    $document = $repository->document()->sole();

    expect($document)
        ->commit_sha->toBe($sha)
        ->content->toBe("# Architecture\n\nA generated repository overview.")
        ->error->toBeNull()
        ->generated_at->not->toBeNull();
    expect(AiUsage::sole())
        ->feature->toBe(AiFeature::Documentation)
        ->model->toBe(config('reviews.model'))
        ->repository_id->toBe($repository->id);

    RepositoryDocumenter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Repository overview from the README.')
        && $prompt->contains('Payment service source details.')
        && ! $prompt->contains('This Markdown source should not be sampled.'));
});

it('bounds the repository tree and sampled file contents by configuration', function () {
    $repository = Repository::factory()->autoDocs()->create(['full_name' => 'acme/docs']);
    $sha = str_repeat('d', 40);
    config([
        'documentation.key_files' => ['README.md'],
        'documentation.source_extensions' => ['php'],
        'documentation.max_tree_entries' => 1,
        'documentation.max_files' => 1,
        'documentation.max_file_characters' => 100,
        'documentation.max_source_characters' => 10,
    ]);
    fakeRepositoryDocumentationGitHub($repository, $sha, [
        ['path' => 'README.md', 'type' => 'blob'],
        ['path' => 'app/PaymentService.php', 'type' => 'blob'],
    ], [
        'README.md' => '1234567890extra README content.',
        'app/PaymentService.php' => 'This source must not fit within the selected file budget.',
    ]);
    RepositoryDocumenter::fake(['# Architecture']);

    runRepositoryDocumentation($repository);

    RepositoryDocumenter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('1234567890')
        && ! $prompt->contains('1234567890e')
        && ! $prompt->contains('app/PaymentService.php')
        && ! $prompt->contains('This source must not fit'));
});

it('does not contact GitHub or the model when automatic documentation is disabled', function () {
    $repository = Repository::factory()->create(['auto_docs_enabled' => false]);
    Http::preventStrayRequests();
    RepositoryDocumenter::fake()->preventStrayPrompts();

    runRepositoryDocumentation($repository);

    Http::assertNothingSent();
    RepositoryDocumenter::assertNeverPrompted();
    expect(AiUsage::count())->toBe(0)
        ->and($repository->document()->exists())->toBeFalse();
});

it('skips documentation generation when the current commit is already documented', function () {
    $repository = Repository::factory()->create(['full_name' => 'acme/docs', 'auto_docs_enabled' => true]);
    $sha = str_repeat('e', 40);
    $repository->document()->create([
        'commit_sha' => $sha,
        'content' => '# Existing documentation',
        'generated_at' => now(),
    ]);
    fakeRepositoryDocumentationGitHub($repository, $sha, []);
    RepositoryDocumenter::fake()->preventStrayPrompts();

    runRepositoryDocumentation($repository);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/git/trees/'));
    RepositoryDocumenter::assertNeverPrompted();
    expect(AiUsage::count())->toBe(0);
});

it('skips documentation generation when the organization is over its AI budget', function () {
    $repository = Repository::factory()->create(['auto_docs_enabled' => true]);
    $repository->organization->update(['monthly_budget_cents' => 1]);
    $repository->organization->aiUsages()->create([
        'feature' => AiFeature::Review,
        'model' => config('reviews.model'),
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cost_micros' => 10_000,
    ]);
    Http::preventStrayRequests();
    RepositoryDocumenter::fake()->preventStrayPrompts();

    runRepositoryDocumentation($repository);

    Http::assertNothingSent();
    RepositoryDocumenter::assertNeverPrompted();
    expect(AiUsage::count())->toBe(1)
        ->and($repository->document()->exists())->toBeFalse();
});

it('stores and reports a GitHub failure without invoking the documentation model', function () {
    $repository = Repository::factory()->autoDocs()->create(['full_name' => 'acme/docs']);
    configureGitHubApp();
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'installation-token'], 201),
        "api.github.com/repos/{$repository->full_name}/commits/*" => Http::response(['message' => 'GitHub unavailable'], 503),
    ]);
    RepositoryDocumenter::fake()->preventStrayPrompts();
    Exceptions::fake();

    runRepositoryDocumentation($repository);

    expect($repository->document()->sole()->error)->toContain('503');
    Exceptions::assertReported(fn (RequestException $exception): bool => str_contains($exception->getMessage(), '503'));
    RepositoryDocumenter::assertNeverPrompted();
});

it('dispatches documentation only for enabled repositories merged into their default branch', function () {
    $installation = GithubInstallation::factory()->create();
    $enabledRepository = Repository::factory()->for($installation, 'installation')->create([
        'organization_id' => $installation->organization_id,
        'full_name' => 'acme/enabled',
        'auto_docs_enabled' => true,
    ]);
    $disabledRepository = Repository::factory()->for($installation, 'installation')->create([
        'organization_id' => $installation->organization_id,
        'full_name' => 'acme/disabled',
        'auto_docs_enabled' => false,
    ]);
    $nonDefaultRepository = Repository::factory()->for($installation, 'installation')->create([
        'organization_id' => $installation->organization_id,
        'full_name' => 'acme/non-default',
        'auto_docs_enabled' => true,
    ]);
    Queue::fake();

    deliverMergedPullRequest($enabledRepository, ['id' => 701, 'number' => 7, 'base_ref' => 'main']);
    deliverMergedPullRequest($disabledRepository, ['id' => 702, 'number' => 8, 'base_ref' => 'main']);
    deliverMergedPullRequest($nonDefaultRepository, ['id' => 703, 'number' => 9, 'base_ref' => 'release']);

    Queue::assertPushed(GenerateRepositoryDocumentation::class, 1, fn (GenerateRepositoryDocumentation $job): bool => $job->repository->is($enabledRepository));
});

it('saves and audits the documentation toggle and immediately queues generation', function () {
    [$member, $organization] = userInOrganization(OrganizationRole::Member);
    $installation = GithubInstallation::factory()->for($organization)->create();
    $repository = Repository::factory()->for($installation, 'installation')->create(['organization_id' => $organization->id]);
    Queue::fake();

    Livewire::actingAs($member)
        ->test('pages::repositories.index')
        ->call('setAutoDocs', $repository->id, true);

    expect($repository->fresh()->auto_docs_enabled)->toBeTrue();
    expect(AuditEvent::sole())
        ->action->toBe('repository.auto_docs_changed')
        ->metadata->toBe(['auto_docs_enabled' => [false, true]]);
    Queue::assertPushed(GenerateRepositoryDocumentation::class, 1, fn (GenerateRepositoryDocumentation $job): bool => $job->repository->is($repository));
});

it('does not queue generation when automatic documentation is switched off', function () {
    [$member, $organization] = userInOrganization(OrganizationRole::Member);
    $installation = GithubInstallation::factory()->for($organization)->create();
    $repository = Repository::factory()->for($installation, 'installation')->autoDocs()->create(['organization_id' => $organization->id]);
    Queue::fake();

    Livewire::actingAs($member)
        ->test('pages::repositories.index')
        ->call('setAutoDocs', $repository->id, false);

    expect($repository->fresh()->auto_docs_enabled)->toBeFalse();
    expect(AuditEvent::sole())
        ->action->toBe('repository.auto_docs_changed')
        ->metadata->toBe(['auto_docs_enabled' => [true, false]]);
    Queue::assertNotPushed(GenerateRepositoryDocumentation::class);
});

it('allows authorized members to regenerate enabled repository documentation', function () {
    [$member, $organization] = userInOrganization(OrganizationRole::Member);
    $installation = GithubInstallation::factory()->for($organization)->create();
    $repository = Repository::factory()->for($installation, 'installation')->autoDocs()->create(['organization_id' => $organization->id]);
    Queue::fake();

    Livewire::actingAs($member)
        ->test('pages::repositories.index')
        ->call('regenerateDocumentation', $repository->id);

    Queue::assertPushed(GenerateRepositoryDocumentation::class, 1, fn (GenerateRepositoryDocumentation $job): bool => $job->repository->is($repository));
});

it('forbids viewers from changing automatic documentation settings', function () {
    [$viewer, $organization] = userInOrganization(OrganizationRole::Viewer);
    $installation = GithubInstallation::factory()->for($organization)->create();
    $repository = Repository::factory()->for($installation, 'installation')->create(['organization_id' => $organization->id]);

    Livewire::actingAs($viewer)
        ->test('pages::repositories.index')
        ->call('setAutoDocs', $repository->id, true)
        ->assertForbidden();

    expect($repository->fresh()->auto_docs_enabled)->toBeFalse();
    expect(AuditEvent::count())->toBe(0);
});

it('renders repository documentation safely and hides documentation from other organizations', function () {
    [$user, $organization] = userInOrganization();
    $installation = GithubInstallation::factory()->for($organization)->create();
    $repository = Repository::factory()->for($installation, 'installation')->create(['organization_id' => $organization->id]);
    $repository->document()->create([
        'commit_sha' => str_repeat('f', 40),
        'content' => "# Architecture\n\nUseful repository overview.\n\n<script>alert('xss')</script>\n\n[Unsafe link](javascript:alert(1))",
        'generated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('repositories.documentation', $repository))
        ->assertOk()
        ->assertSee('Architecture')
        ->assertSee('Useful repository overview.')
        ->assertSee('&lt;script&gt;', false)
        ->assertDontSee("<script>alert('xss')</script>", false)
        ->assertDontSee('href="javascript:', false);

    [$otherUser] = userInOrganization();

    $this->actingAs($otherUser)
        ->get(route('repositories.documentation', $repository))
        ->assertNotFound();
});

it('includes enabled repository documentation in review prompts', function () {
    setUpReviewFixture($this);
    $repository = $this->pullRequest->repository;
    $repository->update(['auto_docs_enabled' => true]);
    $repository->document()->create([
        'commit_sha' => str_repeat('f', 40),
        'content' => 'Repository documentation prompt marker.',
        'generated_at' => now(),
    ]);
    fakeGitHubForReview();
    CodeReviewer::fake([reviewerResponse([])]);
    $context = app(ReviewContextBuilder::class)->build($this->run)->onlyFiles(fn () => true);

    app(ReviewAnalyzer::class)->analyze($this->pullRequest, $context);

    CodeReviewer::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('<repository_documentation>')
        && $prompt->contains('Repository documentation prompt marker.'));
});

it('excludes repository documentation from review prompts when disabled', function () {
    setUpReviewFixture($this);
    $repository = $this->pullRequest->repository;
    $repository->document()->create([
        'commit_sha' => str_repeat('f', 40),
        'content' => 'Disabled repository documentation marker.',
        'generated_at' => now(),
    ]);
    fakeGitHubForReview();
    CodeReviewer::fake([reviewerResponse([])]);
    $context = app(ReviewContextBuilder::class)->build($this->run);

    app(ReviewAnalyzer::class)->analyze($this->pullRequest, $context);

    CodeReviewer::assertPrompted(fn (AgentPrompt $prompt): bool => ! $prompt->contains('Disabled repository documentation marker.'));
});
