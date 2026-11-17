<?php

use App\Enums\OrganizationRole;
use App\Mcp\Servers\DonkeyServer;
use App\Mcp\Tools\GetRepositoryDocumentationTool;
use App\Mcp\Tools\ListLearningsTool;
use App\Mcp\Tools\ListRepositoriesTool;
use App\Mcp\Tools\ListReviewFindingsTool;
use App\Models\Learning;
use App\Models\Organization;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\RepositoryDocument;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
use App\Models\User;
use phpseclib4\Crypt\RSA;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->repository = Repository::factory()->create(['full_name' => 'acme/api']);
    $this->repository->organization->addMember($this->user, OrganizationRole::Member);
    $this->user->switchOrganization($this->repository->organization);

    $this->foreign = Repository::factory()->create(['full_name' => 'other/secret']);
});

it('rejects unauthenticated HTTP requests', function () {
    // CI has no storage/oauth-*.key files: give the Passport guard throwaway keys.
    $key = RSA::createKey(2048);
    config(['passport.private_key' => (string) $key, 'passport.public_key' => (string) $key->getPublicKey()]);

    // MCP clients don't send Accept: application/json; they need a 401 pointing to the OAuth metadata, not a login redirect.
    $this->post('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');
});

it('only rate limits OAuth client registration, not discovery', function () {
    foreach (range(1, 15) as $attempt) {
        $this->get('/.well-known/oauth-protected-resource/mcp')->assertOk();
        $this->get('/.well-known/oauth-authorization-server')->assertOk()->assertJsonPath('registration_endpoint', url('/oauth/register'));
    }

    expect(app('router')->getRoutes()->getByName('mcp.oauth.register')->gatherMiddleware())->toContain('throttle:10,1');
});

it('lists only repositories of the current organization', function () {
    DonkeyServer::actingAs($this->user)->tool(ListRepositoriesTool::class)
        ->assertOk()->assertSee('acme/api')->assertDontSee('other/secret');
});

it('lists findings of a repository and filters by pull request', function () {
    $run = ReviewRun::factory()->for(PullRequest::factory()->for($this->repository)->create(['number' => 7]))->create();
    ReviewFinding::factory()->for($run)->create(['title' => 'Null dereference in checkout']);
    $otherRun = ReviewRun::factory()->for(PullRequest::factory()->for($this->repository)->create(['number' => 8]))->create();
    ReviewFinding::factory()->for($otherRun)->create(['title' => 'Unrelated finding']);

    DonkeyServer::actingAs($this->user)->tool(ListReviewFindingsTool::class, ['repository' => 'acme/api', 'pull_request' => 7])
        ->assertOk()->assertSee('Null dereference in checkout')->assertDontSee('Unrelated finding');
});

it('refuses repositories outside the current organization', function () {
    DonkeyServer::actingAs($this->user)->tool(ListReviewFindingsTool::class, ['repository' => 'other/secret'])->assertHasErrors();
    DonkeyServer::actingAs($this->user)->tool(GetRepositoryDocumentationTool::class, ['repository' => 'other/secret'])->assertHasErrors();
    DonkeyServer::actingAs($this->user)->tool(ListLearningsTool::class, ['repository' => 'other/secret'])->assertHasErrors();
});

it('returns the generated documentation', function () {
    RepositoryDocument::factory()->for($this->repository)->create(['content' => '# Acme API docs']);

    DonkeyServer::actingAs($this->user)->tool(GetRepositoryDocumentationTool::class, ['repository' => 'acme/api'])
        ->assertOk()->assertSee('# Acme API docs');
});

it('lists only active learnings of the organization', function () {
    $organization = $this->repository->organization;
    Learning::factory()->for($organization)->create(['body' => 'Use money objects']);
    Learning::factory()->for($organization)->proposed()->create(['body' => 'Proposed rule']);
    Learning::factory()->for(Organization::factory())->create(['body' => 'Foreign rule']);

    DonkeyServer::actingAs($this->user)->tool(ListLearningsTool::class)
        ->assertOk()->assertSee('Use money objects')->assertDontSee('Proposed rule')->assertDontSee('Foreign rule');
});
