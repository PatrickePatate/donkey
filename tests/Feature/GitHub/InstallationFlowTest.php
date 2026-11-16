<?php

use App\Enums\OrganizationRole;
use App\Jobs\SyncInstallationRepositories;
use App\Models\GithubInstallation;
use App\Models\Repository;
use App\Services\GitHub\GitHubApp;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/GitHubAppTestHelpers.php';

beforeEach(fn () => configureGitHubApp());

/**
 * Start the install flow and return the state GitHub would send back.
 */
function startInstall($test, $user): string
{
    $response = $test->actingAs($user)->get(route('github.install'));
    $response->assertRedirectContains('https://github.com/apps/donkey-test/installations/new');

    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query['state'];
}

function fakeGitHubForInstallation(int $installationId, array $userInstallationIds): void
{
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'user-token']),
        'api.github.com/user/installations*' => Http::response([
            'installations' => array_map(fn (int $id) => ['id' => $id], $userInstallationIds),
        ]),
        "api.github.com/app/installations/{$installationId}" => Http::response([
            'id' => $installationId,
            'account' => ['id' => 777, 'login' => 'acme', 'type' => 'Organization'],
            'repository_selection' => 'all',
        ]),
    ]);
}

it('forbids non-admins from starting the installation', function () {
    [$member] = userInOrganization(OrganizationRole::Member);

    $this->actingAs($member)->get(route('github.install'))->assertForbidden();
});

it('links the installation to the organization that started the flow', function () {
    Bus::fake([SyncInstallationRepositories::class]);
    [$admin, $organization] = userInOrganization(OrganizationRole::Admin);
    fakeGitHubForInstallation(123, [123]);

    $state = startInstall($this, $admin);

    $this->get(route('github.setup', ['installation_id' => 123, 'state' => $state, 'code' => 'oauth-code']))
        ->assertRedirect(route('repositories.index'));

    $installation = GithubInstallation::sole();

    expect($installation)
        ->organization_id->toBe($organization->id)
        ->account_login->toBe('acme')
        ->repository_selection->toBe('all');

    Bus::assertDispatched(SyncInstallationRepositories::class);
});

it('refuses installations the GitHub user cannot access', function () {
    [$admin] = userInOrganization(OrganizationRole::Admin);
    fakeGitHubForInstallation(123, [456]);

    $state = startInstall($this, $admin);

    $this->get(route('github.setup', ['installation_id' => 123, 'state' => $state, 'code' => 'oauth-code']))
        ->assertForbidden();

    expect(GithubInstallation::count())->toBe(0);
});

it('refuses a forged or replayed state', function () {
    [$admin, $organization] = userInOrganization(OrganizationRole::Admin);
    fakeGitHubForInstallation(123, [123]);

    $forged = Crypt::encryptString(json_encode([
        'organization_id' => $organization->id,
        'user_id' => $admin->id,
        'nonce' => 'not-in-session',
        'expires_at' => now()->addHour()->timestamp,
    ]));

    $this->actingAs($admin)
        ->get(route('github.setup', ['installation_id' => 123, 'state' => $forged, 'code' => 'oauth-code']))
        ->assertForbidden();

    expect(GithubInstallation::count())->toBe(0);
});

it('refuses to move an installation linked to another organization', function () {
    [$admin] = userInOrganization(OrganizationRole::Admin);
    GithubInstallation::factory()->create(['installation_id' => 123]);
    fakeGitHubForInstallation(123, [123]);

    $state = startInstall($this, $admin);

    $this->get(route('github.setup', ['installation_id' => 123, 'state' => $state, 'code' => 'oauth-code']))
        ->assertConflict();
});

it('syncs the repositories an installation can access', function () {
    $installation = GithubInstallation::factory()->create(['installation_id' => 321]);
    $stale = Repository::factory()->for($installation, 'installation')->create(['organization_id' => $installation->organization_id]);

    Http::fake([
        'api.github.com/app/installations/321/access_tokens' => Http::response(['token' => 'installation-token'], 201),
        'api.github.com/installation/repositories*' => Http::response([
            'total_count' => 2,
            'repositories' => [
                ['id' => 1, 'full_name' => 'acme/api', 'default_branch' => 'main', 'private' => true],
                ['id' => 2, 'full_name' => 'acme/web', 'default_branch' => 'develop', 'private' => false],
            ],
        ]),
    ]);

    (new SyncInstallationRepositories($installation))->handle(app(GitHubApp::class));

    expect($installation->repositories()->orderBy('full_name')->pluck('full_name')->all())->toBe(['acme/api', 'acme/web'])
        ->and(Repository::find($stale->id))->toBeNull()
        ->and($installation->fresh()->synced_at)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer installation-token')
        && str_contains($request->url(), '/installation/repositories'));
});
