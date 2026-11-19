<?php

namespace Database\Seeders;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\FindingVerification;
use App\Enums\GraphStatus;
use App\Enums\ReviewRunStatus;
use App\Enums\ReviewStage;
use App\Enums\ReviewTrigger;
use App\Enums\RunGraphStatus;
use App\Models\GithubInstallation;
use App\Models\Organization;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\WebhookEvent;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
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
        WebhookEvent::query()->where('installation_id', self::DEMO_INSTALLATION_ID)->delete();

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

        // A real kodus-graph parse of a small billing codebase, so the graph explorer has something to show.
        $fixture = json_decode((string) file_get_contents(__DIR__.'/fixtures/billing-api-graph.json'), true);
        $sha = sha1('base-12');
        $path = rtrim((string) config('graph.graph_path'), '/')."/{$repository->id}/{$sha}.json";
        File::ensureDirectoryExists(dirname($path));
        File::copy(__DIR__.'/fixtures/billing-api-graph.json', $path);

        $repository->graphs()->create([
            'commit_sha' => $sha,
            'status' => GraphStatus::Ready,
            'path' => $path,
            'nodes_count' => count($fixture['nodes']),
            'edges_count' => count($fixture['edges']),
            'files_count' => count(array_unique(array_column($fixture['nodes'], 'file_path'))),
            'size_bytes' => (int) filesize($path),
            'duration_ms' => 1_850,
            'built_at' => now()->subMinutes(12),
        ]);

        $completed = $this->pullRequest($repository->id, 12, 'Charge customers for metered usage', 'ada', 'feature/metered-billing');
        $this->completedRun($completed);

        $running = $this->pullRequest($repository->id, 13, 'Cache invoice totals per account', 'grace', 'feature/invoice-cache');
        $this->reviewRun($running, ReviewRunStatus::Running, ReviewStage::Mapping, startedAgo: 20);

        $failed = $this->pullRequest($repository->id, 14, 'Migrate webhooks to the new queue', 'linus', 'chore/webhook-queue');
        $this->reviewRun($failed, ReviewRunStatus::Failed, ReviewStage::Validating, startedAgo: 600, error: 'Provider timed out after 180 seconds while analyzing chunk 2 of 3.');

        $queued = $this->pullRequest($repository->id, 15, 'Add Stripe tax IDs to customer profile', 'margaret', 'feature/tax-ids');
        $this->reviewRun($queued, ReviewRunStatus::Queued, null, startedAgo: null);

        $merged = $this->pullRequest($repository->id, 9, 'Fix rounding in proration', 'ada', 'fix/proration-rounding', state: 'merged');
        $this->reviewRun($merged, ReviewRunStatus::Completed, null, startedAgo: 86_400, summary: 'Small, well-tested fix to proration rounding. Nothing to flag.');

        $this->activity();
        $this->advisories($repository);
        $this->advisories($installation->repositories()->create([
            'organization_id' => $organization->id,
            'github_id' => 2,
            'full_name' => 'acme-demo/storefront',
            'default_branch' => 'main',
            'is_private' => false,
        ]), storefront: true);

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
        $run->update([
            'risk_score' => 72,
            'files_reviewed' => 6,
            'tokens_input' => 21_480,
            'tokens_output' => 2_910,
            'findings_rejected' => 1,
            'graph_status' => RunGraphStatus::Ready,
            'graph_risk_level' => 'HIGH',
            'graph_risk_score' => 74,
            'graph_summary' => [
                'changed_functions' => [
                    ['name' => 'ChargeService.chargeUsage', 'symbol' => 'app/Billing/ChargeService.php::ChargeService.chargeUsage', 'file' => 'app/Billing/ChargeService.php', 'start' => 52, 'end' => 80, 'callers' => 7, 'tested' => false, 'contract' => [['field' => 'params', 'old' => '(Subscription $subscription)', 'new' => '(Subscription $subscription, Period $period)']], 'impact' => '7 callers may need param update'],
                    ['name' => 'UsageRepository.totalFor', 'symbol' => 'app/Billing/UsageRepository.php::UsageRepository.totalFor', 'file' => 'app/Billing/UsageRepository.php', 'start' => 38, 'end' => 44, 'callers' => 3, 'tested' => true, 'contract' => [], 'impact' => null],
                    ['name' => 'Money.fromCents', 'symbol' => 'app/Billing/Money.php::Money.fromCents', 'file' => 'app/Billing/Money.php', 'start' => 10, 'end' => 14, 'callers' => 0, 'tested' => true, 'contract' => [], 'impact' => null],
                ],
                'test_gaps' => 1,
                'impacted_functions' => 12,
                'impacted_files' => 5,
                'affected_flows' => 2,
            ],
        ]);

        $findings = [
            [
                'severity' => FindingSeverity::Critical, 'category' => FindingCategory::Security, 'confidence' => 92,
                'status' => FindingStatus::Published, 'file' => 'app/Billing/UsageRepository.php', 'lines' => [41, 41],
                'title' => 'SQL injection through an interpolated account id',
                'graph' => ['app/Billing/UsageRepository.php::UsageRepository.totalFor', 3], 'verification' => FindingVerification::Confirmed,
                'body' => 'The account id is interpolated into a raw query. `$accountId` comes from the route, so a crafted value can read other accounts\' usage. Use parameter binding.',
                'suggestion' => '        return DB::select(\'select sum(quantity) as total from usage_records where account_id = ?\', [$accountId]);',
            ],
            [
                'severity' => FindingSeverity::High, 'category' => FindingCategory::Performance, 'confidence' => 85,
                'status' => FindingStatus::Published, 'file' => 'app/Billing/ChargeService.php', 'lines' => [58, 62],
                'title' => 'N+1 queries when totalling usage per subscription',
                'graph' => ['app/Billing/ChargeService.php::ChargeService.chargeUsage', 7], 'verification' => FindingVerification::Confirmed,
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
                'severity' => FindingSeverity::High, 'category' => FindingCategory::Bug, 'confidence' => 70,
                'status' => FindingStatus::Rejected, 'file' => 'app/Billing/ChargeService.php', 'lines' => [66, 66],
                'title' => 'Possible division by zero when a period has no days',
                'body' => 'The per-day rate divides by `$period->days()`, which could be zero.',
                'suggestion' => null, 'verification' => FindingVerification::Rejected,
                'note' => '`Period` enforces end > start in its constructor, so days() is always at least 1.',
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
                'risk_score' => (int) min(100, round($finding['severity']->weight() * $finding['confidence'] / 100 * (isset($finding['graph']) ? 1.2 : 1))),
                'file_path' => $finding['file'],
                'start_line' => $finding['lines'][0],
                'end_line' => $finding['lines'][1],
                'title' => $finding['title'],
                'body' => $finding['body'],
                'suggestion' => $finding['suggestion'],
                'status' => $finding['status'],
                'graph_symbol' => $finding['graph'][0] ?? null,
                'graph_callers' => $finding['graph'][1] ?? null,
                'verification' => $finding['verification'] ?? null,
                'verifier_note' => $finding['note'] ?? null,
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

    /**
     * A feed of webhook deliveries covering every tone of the live activity card.
     */
    private function activity(): void
    {
        $repository = ['full_name' => 'acme-demo/billing-api'];
        $events = [
            ['pull_request', 'opened', ['pull_request' => ['number' => 15]], 'processed', 2],
            ['pull_request_review_thread', 'resolved', ['pull_request' => ['number' => 12]], 'processed', 18],
            ['issue_comment', 'created', ['issue' => ['number' => 12]], 'processed', 35],
            ['pull_request', 'synchronize', ['pull_request' => ['number' => 13]], 'processed', 50],
            ['pull_request', 'synchronize', ['pull_request' => ['number' => 14]], 'failed', 70],
            ['pull_request_review_thread', 'unresolved', ['pull_request' => ['number' => 12]], 'processed', 120],
            ['pull_request', 'closed', ['pull_request' => ['number' => 9, 'merged' => true]], 'processed', 180],
            ['installation_repositories', 'added', [], 'processed', 1_440],
            ['installation', 'created', [], 'processed', 1_500],
        ];

        foreach ($events as [$event, $action, $payload, $status, $minutesAgo]) {
            WebhookEvent::factory()->create([
                'event' => $event,
                'action' => $action,
                'installation_id' => self::DEMO_INSTALLATION_ID,
                'payload' => ['repository' => $repository, ...$payload],
                'status' => $status,
                'created_at' => now()->subMinutes($minutesAgo),
            ]);
        }
    }

    /**
     * Demo vulnerabilities in the Composer dependencies (illustrative ids, not real advisories).
     */
    private function advisories(Repository $repository, bool $storefront = false): void
    {
        $advisories = $storefront
            ? [
                ['acme/image-resizer', '2.3.0', false, 'Path traversal when resizing uploaded images', FindingSeverity::High, '<2.3.4', 9],
                ['acme/markdown-lite', '0.9.1', false, 'Cross-site scripting through link titles', FindingSeverity::Medium, '<0.9.5', 21],
            ]
            : [
                ['acme/http-client', '7.4.0', false, 'Authorization header forwarded on cross-domain redirects', FindingSeverity::Critical, '>=7.0,<7.4.5', 3],
                ['acme/jwt-tools', '5.1.2', false, 'Signature check skipped for tokens using the "none" algorithm', FindingSeverity::High, '<5.2.0', 12],
                ['acme/xml-parser', '1.8.0', false, 'External entity expansion allows reading local files', FindingSeverity::Medium, '<1.8.3', 30],
                ['acme/debug-bar', '3.0.1', true, 'Debug toolbar exposes environment variables when enabled', FindingSeverity::Low, '<3.0.4', 60],
            ];

        $repository->update([
            'dependencies_scanned_at' => now()->subHours(2),
            'dependencies_commit_sha' => sha1("deps-{$repository->full_name}"),
            'composer_packages_count' => $storefront ? 64 : 112,
        ]);

        foreach ($advisories as $index => [$package, $version, $isDev, $title, $severity, $affected, $daysAgo]) {
            $repository->securityAdvisories()->create([
                'package' => $package,
                'installed_version' => $version,
                'is_dev' => $isDev,
                'advisory_id' => sprintf('DEMO-%d-%02d', $repository->id, $index + 1),
                'title' => $title,
                'cve' => null,
                'link' => "https://packagist.org/packages/{$package}/advisories",
                'severity' => $severity,
                'affected_versions' => $affected,
                'reported_at' => now()->subDays($daysAgo),
            ]);
        }
    }
}
