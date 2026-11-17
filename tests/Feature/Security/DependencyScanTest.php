<?php

use App\Enums\FindingSeverity;
use App\Enums\OrganizationRole;
use App\Jobs\ScanRepositoryDependencies;
use App\Models\GithubInstallation;
use App\Models\Repository;
use App\Models\SecurityAdvisory;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

require_once __DIR__.'/../GitHub/GitHubAppTestHelpers.php';

function fakeDependencyScan(Repository $repository, ?string $composerLock, mixed $packagist = null): void
{
    configureGitHubApp();
    Http::preventStrayRequests();

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'installation-token'], 201),
        "api.github.com/repos/{$repository->full_name}/commits/*" => Http::response(['sha' => str_repeat('d', 40)]),
        "api.github.com/repos/{$repository->full_name}/contents/composer.lock*" => $composerLock === null ? Http::response(['message' => 'Not Found'], 404) : Http::response($composerLock),
        'packagist.org/api/security-advisories/' => $packagist ?? Http::response(['advisories' => [
            'acme/http' => [
                ['advisoryId' => 'PKSA-1', 'title' => 'Header injection', 'link' => 'https://example.com/1', 'cve' => 'CVE-2026-1', 'affectedVersions' => '>=2.0.0,<2.4.1|<1.9.0', 'reportedAt' => '2026-10-01 10:00:00', 'severity' => 'high'],
                ['advisoryId' => 'PKSA-2', 'title' => 'Fixed long ago', 'link' => null, 'cve' => null, 'affectedVersions' => '<2.0.0', 'reportedAt' => '2025-01-01 10:00:00', 'severity' => 'critical'],
            ],
            'acme/testing' => [
                ['advisoryId' => 'PKSA-3', 'title' => 'Unsafe fixtures', 'link' => null, 'cve' => null, 'affectedVersions' => '<5.0', 'reportedAt' => null, 'severity' => null],
            ],
            'acme/edge' => [
                ['advisoryId' => 'PKSA-4', 'title' => 'Branch only', 'link' => null, 'cve' => null, 'affectedVersions' => '<9.0', 'reportedAt' => null, 'severity' => 'low'],
            ],
        ]]),
    ]);
}

function composerLock(): string
{
    return json_encode([
        'packages' => [
            ['name' => 'acme/http', 'version' => 'v2.3.0'],
            ['name' => 'acme/edge', 'version' => 'dev-main'],
        ],
        'packages-dev' => [
            ['name' => 'Acme/Testing', 'version' => '4.2.0'],
        ],
    ]);
}

function scanDependencies(Repository $repository): void
{
    app()->call([new ScanRepositoryDependencies($repository), 'handle']);
}

beforeEach(function () {
    $this->repository = Repository::factory()->for(GithubInstallation::factory(), 'installation')->create();
});

it('records the advisories that affect the locked versions', function () {
    fakeDependencyScan($this->repository, composerLock());

    scanDependencies($this->repository);

    $advisories = $this->repository->securityAdvisories()->orderBy('advisory_id')->get();

    expect($advisories->pluck('advisory_id')->all())->toBe(['PKSA-1', 'PKSA-3'])
        ->and($advisories[0])->package->toBe('acme/http')->installed_version->toBe('v2.3.0')->severity->toBe(FindingSeverity::High)->is_dev->toBeFalse()
        ->and($advisories[1])->package->toBe('acme/testing')->severity->toBeNull()->is_dev->toBeTrue()
        ->and($this->repository->fresh())
        ->composer_packages_count->toBe(3)
        ->dependencies_commit_sha->toBe(str_repeat('d', 40))
        ->dependencies_scan_error->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'packagist.org') && $request['packages'] === ['acme/http', 'acme/edge', 'acme/testing']);
});

it('clears the advisories when the repository has no composer.lock', function () {
    SecurityAdvisory::factory()->for($this->repository)->create();
    fakeDependencyScan($this->repository, null);

    scanDependencies($this->repository);

    expect($this->repository->securityAdvisories()->count())->toBe(0)
        ->and($this->repository->fresh())->composer_packages_count->toBeNull()->dependencies_scanned_at->not->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'packagist.org'));
});

it('keeps the previous advisories when Packagist fails', function () {
    Exceptions::fake();
    SecurityAdvisory::factory()->for($this->repository)->create();
    fakeDependencyScan($this->repository, composerLock(), Http::response('Unavailable', 503));

    scanDependencies($this->repository);

    expect($this->repository->securityAdvisories()->count())->toBe(1)
        ->and($this->repository->fresh()->dependencies_scan_error)->not->toBeNull();
});

it('shows the organization advisories and lets admins run a check', function () {
    Queue::fake();
    [$owner, $organization] = userInOrganization(OrganizationRole::Owner);
    $repository = Repository::factory()->for(GithubInstallation::factory()->for($organization), 'installation')->create(['organization_id' => $organization->id, 'dependencies_scanned_at' => now(), 'composer_packages_count' => 12]);
    SecurityAdvisory::factory()->for($repository)->create(['title' => 'Our advisory', 'severity' => FindingSeverity::Critical]);
    SecurityAdvisory::factory()->create(['title' => 'Another organization advisory']);

    Livewire::actingAs($owner)->test('pages::security.index')
        ->assertSee('Our advisory')
        ->assertDontSee('Another organization advisory')
        ->set('severity', 'low')
        ->assertDontSee('Our advisory')
        ->call('scan');

    Queue::assertPushed(ScanRepositoryDependencies::class, fn (ScanRepositoryDependencies $job) => $job->repository->is($repository));
});

it('does not let viewers run a check', function () {
    [$viewer, $organization] = userInOrganization(OrganizationRole::Viewer);
    Repository::factory()->for(GithubInstallation::factory()->for($organization), 'installation')->create(['organization_id' => $organization->id]);

    Livewire::actingAs($viewer)->test('pages::security.index')->call('scan')->assertForbidden();
});

it('summarizes the affected projects and packages on the dashboard', function () {
    [$owner, $organization] = userInOrganization(OrganizationRole::Owner);
    $installation = GithubInstallation::factory()->for($organization)->create();
    [$first, $second] = Repository::factory()->count(3)->for($installation, 'installation')->create(['organization_id' => $organization->id, 'composer_packages_count' => 10]);
    SecurityAdvisory::factory()->for($first)->create(['package' => 'acme/http', 'severity' => FindingSeverity::Low]);
    SecurityAdvisory::factory()->for($second)->create(['package' => 'acme/http', 'severity' => FindingSeverity::Critical]);
    SecurityAdvisory::factory()->for($second)->create(['package' => 'acme/mail', 'severity' => FindingSeverity::Medium]);

    $security = Livewire::actingAs($owner)->test('pages::dashboard')->assertSee('acme/http')->get('security');

    expect($security)->toMatchArray(['checked' => 3, 'affected' => 2, 'severe' => 1])
        ->and($security['packages'])->toBe([
            ['package' => 'acme/http', 'severity' => 'critical', 'repositories' => 2],
            ['package' => 'acme/mail', 'severity' => 'medium', 'repositories' => 1],
        ]);
});

it('scans the configured branch instead of the default one', function () {
    $this->repository->update(['security_branch' => 'production']);
    fakeDependencyScan($this->repository, composerLock());

    scanDependencies($this->repository);

    Http::assertSent(fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/commits/production'));
});

it('lets admins pick the monitored branch once it exists on GitHub', function () {
    Queue::fake();
    [$owner, $organization] = userInOrganization(OrganizationRole::Owner);
    $repository = Repository::factory()->for(GithubInstallation::factory()->for($organization), 'installation')->create(['organization_id' => $organization->id]);
    configureGitHubApp();
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'installation-token'], 201),
        '*/commits/production' => Http::response(['sha' => str_repeat('p', 40)]),
        '*/commits/*' => Http::response(['message' => 'No commit found'], 422),
    ]);

    $page = Livewire::actingAs($owner)->test('pages::security.index', ['repository' => $repository->id]);

    $page->set('securityBranch', 'nope')->call('saveSecurityBranch')->assertHasErrors('securityBranch');
    expect($repository->fresh()->security_branch)->toBeNull();

    $page->set('securityBranch', 'production')->call('saveSecurityBranch')->assertHasNoErrors();
    expect($repository->fresh()->securityBranch())->toBe('production');
    Queue::assertPushed(ScanRepositoryDependencies::class);

    $page->set('securityBranch', '')->call('saveSecurityBranch');
    expect($repository->fresh()->securityBranch())->toBe('main');
});
