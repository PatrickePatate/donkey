<?php

use App\Actions\ProposeDonkeySkill;
use App\Enums\OrganizationRole;
use App\Models\GithubInstallation;
use App\Models\Repository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

require_once __DIR__.'/../GitHub/GitHubAppTestHelpers.php';

beforeEach(function () {
    configureGitHubApp();
    [$this->member, $organization] = userInOrganization(OrganizationRole::Member);
    $installation = GithubInstallation::factory()->for($organization)->create();
    $this->repository = Repository::factory()->for($installation, 'installation')->create(['organization_id' => $organization->id, 'full_name' => 'acme/api']);
});

it('opens a pull request adding the skill with every MCP tool documented', function () {
    Http::fake([
        '*/access_tokens' => Http::response(['token' => 'installation-token', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        '*/repos/acme/api/commits/main' => Http::response(['sha' => 'base-sha']),
        '*/repos/acme/api/git/refs' => Http::response([], 201),
        '*/repos/acme/api/contents/*' => Http::sequence()->push([], 404)->push([], 201),
        '*/repos/acme/api/pulls?*' => Http::response([]),
        '*/repos/acme/api/pulls' => Http::response(['html_url' => 'https://github.com/acme/api/pull/42'], 201),
    ]);

    Livewire::actingAs($this->member)
        ->test('pages::repositories.show', ['repository' => $this->repository->id])
        ->call('proposeSkill')
        ->assertRedirect('https://github.com/acme/api/pull/42');

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'PUT') {
            return false;
        }

        $skill = base64_decode($request['content']);

        return str_contains($request->url(), '.claude/skills/donkey/SKILL.md')
            && $request['branch'] === ProposeDonkeySkill::BRANCH
            && !isset($request['sha'])
            && str_contains($skill, 'name: donkey')
            && str_contains($skill, url('/mcp'))
            && collect(['list-repositories', 'list-review-findings', 'get-repository-documentation', 'list-learnings'])->every(fn (string $tool): bool => str_contains($skill, "### `{$tool}`"));
    });
});

it('updates the existing branch and reuses its open pull request', function () {
    Http::fake([
        '*/access_tokens' => Http::response(['token' => 'installation-token', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        '*/repos/acme/api/commits/main' => Http::response(['sha' => 'base-sha']),
        '*/repos/acme/api/git/refs' => Http::response(['message' => 'Reference already exists'], 422),
        '*/repos/acme/api/contents/*' => Http::sequence()->push(['sha' => 'old-sha'])->push([], 200),
        '*/repos/acme/api/pulls?*' => Http::response([['html_url' => 'https://github.com/acme/api/pull/7']]),
    ]);

    expect(app(ProposeDonkeySkill::class)->handle($this->repository))->toBe('https://github.com/acme/api/pull/7');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request['sha'] === 'old-sha');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls'));
});

it('forbids viewers from proposing the skill', function () {
    [$viewer] = userInOrganization(OrganizationRole::Viewer);
    $viewer->organizations()->attach($this->repository->organization, ['role' => OrganizationRole::Viewer]);
    $viewer->switchOrganization($this->repository->organization);

    Livewire::actingAs($viewer)
        ->test('pages::repositories.show', ['repository' => $this->repository->id])
        ->call('proposeSkill')
        ->assertForbidden();
});
