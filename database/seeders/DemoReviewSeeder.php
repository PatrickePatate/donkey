<?php

namespace Database\Seeders;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\ReviewRunStatus;
use App\Enums\ReviewStage;
use App\Enums\ReviewTrigger;
use App\Models\GithubInstallation;
use App\Models\Organization;
use App\Models\PullRequest;
use App\Models\ReviewRun;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Local demo data: a fake GitHub connection with pull requests in every review state.
 *
 * Run with: php artisan db:seed --class=DemoReviewSeeder
 */
class DemoReviewSeeder extends Seeder
{
    use WithoutModelEvents;

    private const DEMO_INSTALLATION_ID = 1;

    public function run(): void
    {
        $organization = Organization::query()->oldest('id')->first();

        if ($organization === null) {
            $this->command?->warn('Create an organization first (sign up in the app), then run this seeder again.');

            return;
        }

        GithubInstallation::query()->where('installation_id', self::DEMO_INSTALLATION_ID)->delete();

        $installation = $organization->githubInstallations()->create([
            'installation_id' => self::DEMO_INSTALLATION_ID,
            'account_id' => 1,
            'account_login' => 'acme-demo',
            'account_type' => 'Organization',
            'repository_selection' => 'selected',
            'synced_at' => now(),
        ]);

        $repository = $installation->repositories()->create([
            'organization_id' => $organization->id,
            'github_id' => 1,
            'full_name' => 'acme-demo/billing-api',
            'default_branch' => 'main',
            'is_private' => true,
        ]);

        $completed = $this->pullRequest($repository->id, 12, 'Charge customers for metered usage', 'ada', 'feature/metered-billing');
        $this->completedRun($completed);

        $running = $this->pullRequest($repository->id, 13, 'Cache invoice totals per account', 'grace', 'feature/invoice-cache');
        $this->reviewRun($running, ReviewRunStatus::Running, ReviewStage::Analyzing, startedAgo: 20);

        $failed = $this->pullRequest($repository->id, 14, 'Migrate webhooks to the new queue', 'linus', 'chore/webhook-queue');
        $this->reviewRun($failed, ReviewRunStatus::Failed, ReviewStage::Validating, startedAgo: 600, error: 'Provider timed out after 180 seconds while analyzing chunk 2 of 3.');

        $queued = $this->pullRequest($repository->id, 15, 'Add Stripe tax IDs to customer profile', 'margaret', 'feature/tax-ids');
        $this->reviewRun($queued, ReviewRunStatus::Queued, null, startedAgo: null);

        $merged = $this->pullRequest($repository->id, 9, 'Fix rounding in proration', 'ada', 'fix/proration-rounding', state: 'merged');
        $this->reviewRun($merged, ReviewRunStatus::Completed, null, startedAgo: 86_400, summary: 'Small, well-tested fix to proration rounding. Nothing to flag.');

        $this->command?->info("Seeded demo pull requests for {$organization->name}.");
    }

    private function pullRequest(int $repositoryId, int $number, string $title, string $author, string $branch, string $state = 'open'): PullRequest
    {
        return PullRequest::query()->create([
            'repository_id' => $repositoryId,
            'github_id' => 100_000 + $number,
            'number' => $number,
            'title' => $title,
            'body' => "Demo pull request #{$number}.",
            'author_login' => $author,
            'state' => $state,
            'is_draft' => false,
            'head_sha' => sha1("head-{$number}"),
            'base_sha' => sha1("base-{$number}"),
            'head_ref' => $branch,
            'base_ref' => 'main',
            'html_url' => "https://github.com/acme-demo/billing-api/pull/{$number}",
            'last_reviewed_sha' => $state === 'merged' ? sha1("head-{$number}") : null,
        ]);
    }

    private function reviewRun(
        PullRequest $pullRequest,
        ReviewRunStatus $status,
        ?ReviewStage $stage,
        ?int $startedAgo,
        ?string $error = null,
        ?string $summary = null,
    ): ReviewRun {
        $finished = $status->isFinished();

        return $pullRequest->reviewRuns()->create([
            'idempotency_key' => (string) Str::uuid(),
            'head_sha' => $pullRequest->head_sha,
            'base_sha' => $pullRequest->base_sha,
            'trigger' => ReviewTrigger::Auto,
            'triggered_by' => $pullRequest->author_login,
            'status' => $status,
            'stage' => $stage,
            'risk_score' => $finished ? 18 : null,
            'risk_threshold' => 50,
            'summary' => $summary,
            'files_reviewed' => $status === ReviewRunStatus::Queued ? 0 : 4,
            'provider' => 'anthropic',
            'model' => config('reviews.model'),
            'tokens_input' => $finished ? 9_400 : 0,
            'tokens_output' => $finished ? 1_200 : 0,
            'error' => $error,
            'started_at' => $startedAgo === null ? null : now()->subSeconds($startedAgo),
            'completed_at' => $finished && $startedAgo !== null ? now()->subSeconds($startedAgo - 47) : null,
        ]);
    }

    private function completedRun(PullRequest $pullRequest): void
    {
        $earlier = $this->reviewRun($pullRequest, ReviewRunStatus::Completed, null, startedAgo: 7_200, summary: 'First pass over the metered billing changes.');
        $earlier->update(['head_sha' => sha1('head-12-previous')]);

        $run = $this->reviewRun($pullRequest, ReviewRunStatus::Completed, null, startedAgo: 300, summary: implode("\n\n", [
            'Adds **metered usage billing**: usage records are aggregated per billing period and charged through `ChargeService`.',
            'The main risk is in how usage totals are queried: one raw SQL query interpolates the account id, and the aggregation runs one query per subscription.',
        ]));
        $run->update(['risk_score' => 72, 'files_reviewed' => 6, 'tokens_input' => 18_250, 'tokens_output' => 2_310]);

        $findings = [
            [
                'severity' => FindingSeverity::Critical, 'category' => FindingCategory::Security, 'confidence' => 92,
                'status' => FindingStatus::Published, 'file' => 'app/Billing/UsageRepository.php', 'lines' => [41, 41],
                'title' => 'SQL injection through an interpolated account id',
                'body' => 'The account id is interpolated into a raw query. `$accountId` comes from the route, so a crafted value can read other accounts\' usage. Use parameter binding.',
                'suggestion' => '        return DB::select(\'select sum(quantity) as total from usage_records where account_id = ?\', [$accountId]);',
            ],
            [
                'severity' => FindingSeverity::High, 'category' => FindingCategory::Performance, 'confidence' => 85,
                'status' => FindingStatus::Published, 'file' => 'app/Billing/ChargeService.php', 'lines' => [58, 62],
                'title' => 'N+1 queries when totalling usage per subscription',
                'body' => 'Each subscription triggers its own usage query inside the loop. With a few hundred subscriptions per account this becomes hundreds of queries per invoice run. Eager-load or aggregate in one grouped query.',
                'suggestion' => null,
            ],
            [
                'severity' => FindingSeverity::Medium, 'category' => FindingCategory::Logic, 'confidence' => 70,
                'status' => FindingStatus::Suppressed, 'file' => 'app/Billing/ChargeService.php', 'lines' => [74, 74],
                'title' => 'Usage on the last day of the period may be billed twice',
                'body' => 'The period end is inclusive here but exclusive in `UsageRepository::forPeriod()`, so records at exactly midnight fall into both periods.',
                'suggestion' => null,
            ],
            [
                'severity' => FindingSeverity::Low, 'category' => FindingCategory::Maintainability, 'confidence' => 60,
                'status' => FindingStatus::Suppressed, 'file' => 'app/Billing/Money.php', 'lines' => [12, 12],
                'title' => 'Magic number for the cents divisor',
                'body' => 'Consider a named constant for `100` so currency precision is explicit.',
                'suggestion' => '    private const CENTS_PER_UNIT = 100;',
            ],
        ];

        foreach ($findings as $index => $finding) {
            $run->findings()->create([
                'pull_request_id' => $pullRequest->id,
                'fingerprint' => sha1("demo-{$index}"),
                'category' => $finding['category'],
                'severity' => $finding['severity'],
                'confidence' => $finding['confidence'],
                'risk_score' => (int) round($finding['severity']->weight() * $finding['confidence'] / 100),
                'file_path' => $finding['file'],
                'start_line' => $finding['lines'][0],
                'end_line' => $finding['lines'][1],
                'title' => $finding['title'],
                'body' => $finding['body'],
                'suggestion' => $finding['suggestion'],
                'status' => $finding['status'],
                'github_comment_id' => $finding['status'] === FindingStatus::Published ? 5_000 + $index : null,
            ]);
        }

        $earlier->findings()->create([
            'pull_request_id' => $pullRequest->id,
            'fingerprint' => sha1('demo-outdated'),
            'category' => FindingCategory::Bug,
            'severity' => FindingSeverity::High,
            'confidence' => 80,
            'risk_score' => 60,
            'file_path' => 'app/Billing/ChargeService.php',
            'start_line' => 30,
            'end_line' => 30,
            'title' => 'Charge amount is computed before currency conversion',
            'body' => 'Fixed by a later commit.',
            'status' => FindingStatus::Outdated,
        ]);
    }
}
