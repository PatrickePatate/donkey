<?php

use App\Jobs\ReviewPullRequest;
use App\Models\GithubInstallation;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\ReviewRun;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../GitHub/GitHubAppTestHelpers.php';

const BILLING_PATCH = <<<'PATCH'
@@ -1,5 +1,7 @@
 <?php
 
 class Billing
 {
-    public function charge($user) {}
+    public function charge($user, $amount)
+    {
+        return DB::select("select * from invoices where user = $user");
+    }
 }
PATCH;

/**
 * Fake the GitHub API for a review; individual tests can override responses.
 *
 * @param  array<string, mixed>  $overrides
 */
function fakeGitHubForReview(array $overrides = []): void
{
    Http::fake([
        ...$overrides,
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'installation-token'], 201),
        'api.github.com/repos/acme/app/pulls/7/files*' => Http::response([
            ['filename' => 'app/Billing.php', 'status' => 'modified', 'patch' => BILLING_PATCH],
            ['filename' => 'composer.lock', 'status' => 'modified', 'patch' => '@@ -1 +1 @@'],
        ]),
        'api.github.com/repos/acme/app/contents/CLAUDE.md*' => Http::response('Always use parameter binding for SQL.'),
        'api.github.com/repos/acme/app/contents/*' => Http::response('', 404),
        'api.github.com/repos/acme/app/check-runs/*' => Http::response([]),
        'api.github.com/repos/acme/app/check-runs' => Http::response(['id' => 321], 201),
        'api.github.com/repos/acme/app/pulls/7/reviews/777/comments*' => Http::response([
            ['id' => 9001, 'path' => 'app/Billing.php', 'line' => 7],
        ]),
        'api.github.com/repos/acme/app/pulls/7/reviews' => Http::response(['id' => 777]),
    ]);
}

/**
 * @return array<string, mixed>
 */
function reviewerResponse(array $findings): array
{
    return ['summary' => 'Adds an amount to `charge`.', 'risk_score' => 70, 'findings' => $findings];
}

/**
 * @return array<string, mixed>
 */
function sqlInjectionFinding(array $overrides = []): array
{
    return [
        'file' => 'app/Billing.php',
        'start_line' => 7,
        'end_line' => 7,
        'category' => 'security',
        'severity' => 'critical',
        'confidence' => 90,
        'title' => 'SQL injection through string interpolation',
        'explanation' => 'User input is interpolated into raw SQL.',
        'suggestion' => '        return DB::select("select * from invoices where user = ?", [$user]);',
        ...$overrides,
    ];
}

function runReview(ReviewRun $run): void
{
    app()->call([new ReviewPullRequest($run), 'handle']);
}

/**
 * A connected repository with PR #7 (head aaaa…, base bbbb…) and a queued review run on $test.
 */
function setUpReviewFixture(object $test): void
{
    configureGitHubApp();

    $installation = GithubInstallation::factory()->create();
    $installation->organization->update(['risk_threshold' => 50]);

    $test->repository = Repository::factory()->for($installation, 'installation')->create([
        'organization_id' => $installation->organization_id,
        'full_name' => 'acme/app',
    ]);

    $test->pullRequest = PullRequest::factory()->for($test->repository)->create([
        'number' => 7,
        'head_sha' => str_repeat('a', 40),
        'base_sha' => str_repeat('b', 40),
    ]);

    $test->run = ReviewRun::factory()->for($test->pullRequest)->create(['head_sha' => str_repeat('a', 40)]);
}
