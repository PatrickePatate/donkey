# Phase 6 hand-off: hardening, cost monitoring, email-domain auto-join

This file is for the next agent picking up Phase 6. Read it top to bottom before you touch any code.

**State of play.** All work is on branch `phase-6-hardening`, which was cut from `phase-5-learning` (`c762f16`). Nothing in Phase 6 is committed yet.

The branch chain is `main` → `phase-3-context` → `phase-4-conversation` → `phase-5-learning` → `phase-6-hardening`. None of these branches is merged into `main`.

The phase brief, from the user:
- the hardening items from the dev plan:
  - an evaluation harness
  - rate limits and budgets
  - a retention policy
  - an audit log
  - monitoring
- **auto-join by verified organization email domain**
- **AI cost monitoring that only the organization owner can see**

## Environment notes

- **PHP:** run everything with `php8.5`, never plain `php`, which is 8.4. Larastan is `php8.5 vendor/bin/phpstan analyse --memory-limit=1G`.
- **Database and Redis:** they run in Docker. Postgres with pgvector is on port 5434 and Redis on port 6380. The app, Horizon and Reverb run natively.
- **Tests:**
  - Run them with `php8.5 artisan test --compact`.
  - `tests/TestCase.php` calls `Http::preventStrayRequests()`, so every HTTP request in a test must be faked.
  - Queued jobs run synchronously in tests. Fake jobs that would call the model, for example `Queue::fake([ProposeLearnings::class])`.
- **The user's local edit:** `database/seeders/DatabaseSeeder.php` has an uncommitted change by the user (a test password). Never commit it; previous phases staged everything except that file.
- **Commit messages** end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Pint:** run `vendor/bin/pint --dirty --format agent` before committing.

## Done so far (uncommitted)

### Cost monitoring
- **Configuration:**
  - `config/ai_pricing.php` holds the price per million tokens. The Anthropic list prices are Sonnet 5.5 $2/$10, Opus 5.5 $4/$20, Haiku 5.5 $0.10/$0.50 and Fable 5.2 $8/$40.
  - It also sets the alert thresholds at 80% and 100% of the budget.
- **Usage ledger:**
  - The `ai_usages` table, with the `AiUsage` model, factory and the `AiFeature` enum.
  - Each row stores the organization, repository, pull request, feature, provider, model, input and output tokens, and the cost in millionths of a dollar (`cost_micros`).
- **Organization fields:** a migration adds `monthly_budget_cents`, `auto_join_domains` (JSON) and `auto_join_role` to `organizations`. The fields and casts are on `Organization`.
- **`App\Services\Usage\UsageMeter`:**
  - `record()`, `costMicros()`, `monthToDateMicros()` and `isOverBudget()`.
  - It emails owners once per month at each threshold, using `Cache::add` to send each email only once. The email is `App\Mail\BudgetThresholdReached` with the view `mail/budget-threshold-reached`.
- **What gets metered:**
  - `ReviewAnalyzer`, as the Review or Agent feature. It skips unsaved pull requests, which is how the eval harness avoids being billed.
  - `FindingVerifierService`, `AnswerPullRequestComment` and `ProposeLearnings`.
- **Budget enforcement:**
  - `ReviewPullRequest` skips the run with the reason "monthly AI budget" and completes a check run as skipped.
  - `AnswerPullRequestComment` replies that the budget is reached and marks the question Failed.
  - `ProposeLearnings` does nothing.
- **The `UsageReport` service** builds the month's totals, a projection, daily spend, and breakdowns by feature, repository, model and pull request.
- **Page `pages::settings.usage`** at `/settings/usage`:
  - It is owner-only, through the `OrganizationPolicy::viewUsage` permission.
  - It has a month picker, totals, a budget progress bar with an edit form (audited), daily bars and the breakdowns.
  - The nav shows "Usage & cost" in Settings for owners only.

### Auto-join by email domain
- **Email verification:**
  - `User` now implements `MustVerifyEmail`, so Laravel sends the verification email when a user registers.
  - The routes are `verification.notice` (Livewire page `pages::auth.verify-email`, with a resend button limited to 3 per 10 minutes) and `verification.verify` (`App\Http\Controllers\Auth\VerifyEmailController`).
  - Registration now redirects to `verification.notice`, and `tests/Feature/Auth/AuthenticationTest.php` was updated to expect that.
- **`App\Actions\AutoJoinOrganizations`:**
  - It only applies to verified emails and ignores public mail providers (listed in `config/auto_join.php`).
  - It joins the user with the organization's `auto_join_role`, records an audit event, and switches the current organization if the user had none.
  - Listeners in `AppServiceProvider::boot` run it on the `Verified` and `Login` events.
- **Organization settings page** (`pages/settings/organization.blade.php`), new "Joining" card:
  - Domains textarea and role (member or viewer).
  - Validation rejects:
    - invalid domains
    - public providers
    - a domain another organization already claimed
    - adding a new domain unless it matches the saving admin's own verified email domain
  - Saving records an `organization.updated` audit event with the changes.

### Audit log
- **Storage:** the `audit_events` table, with the `AuditEvent` model (prunable) and its factory.
- **Recording:** `App\Services\Audit\AuditLog::record()`; the static `changes()` helper lists changed values as old and new.
- **What is recorded:**
  - members: invited, role changed, removed or left, invitation revoked
  - repositories: auto-review and threshold changes
  - agents: created, updated, deleted, enabled or disabled
  - learnings: approved, rejected, archived or reactivated, updated
  - memories: deleted
  - GitHub installs: `github.connected` in `SetupController`
  - budget changes, organization settings, auto-join
- **Page `pages::settings.audit`** at `/settings/audit`: admins only (`OrganizationPolicy::viewAudit`), with a search box and simple pagination. The nav entry shows for admins.

### Hardening
- **GitHub rate limits** (`GitHubApp`):
  - The HTTP client retries 3 times on 5xx, 429 and 403 rate-limit responses.
  - `GitHubApp::isRateLimited()` recognizes them: either the remaining-requests header is at 0, there is a `Retry-After` header, or the body says "secondary rate limit".
  - The wait follows `Retry-After` or the reset time, capped at 30 seconds.
- **Job-level retry:**
  - `App\Support\TransientFailure::backoff()` covers provider rate limits and overloads, plus GitHub rate limits and 502/503/504 responses.
  - `ReviewPullRequest` sets the run back to Queued and releases the job, up to 6 attempts. On a retry it deletes the run's findings and reuses the existing check run.
  - `AnswerPullRequestComment` now has `$tries = 4` and releases on these failures.
- **Retention:**
  - `config/retention.php` sets webhook events to 30 days, usage to 400, audit to 365 and conversations to 365.
  - `WebhookEvent`, `ConversationMessage`, `AiUsage` and `AuditEvent` are prunable.
  - `model:prune` is scheduled daily at 02:30 in `routes/console.php`.
- **New icons and components from earlier phases** are available: textarea, checkbox, bot, lightbulb, pin, archive, flask, pencil and message-square.

### Evaluation harness
- **Cases:** `tests/Evals/cases/*.json` holds five seeded cases: sql-injection, missing-authorization, n-plus-one, off-by-one and clean-refactor (which expects nothing).
- **Command `php8.5 artisan eval:run`** (`app/Console/Commands/RunEvaluations.php`):
  - Options: `--case=`, `--model=`, `--threshold=50`, `--json=` and `--force`.
  - It runs `ReviewAnalyzer` on unsaved pull requests and matches findings to expectations by file and line, with ±3 lines of slack.
  - It prints recall, precision, precision of the findings that would be posted, severity accuracy (within one level) and cost.
  - It asks for confirmation before calling the model, since each run costs money.
  - It has only been checked with `--help`; it has never been run against a real model.

## Progress update

Tasks 1–3 below are done:
- `UsageAndBudgetTest` passes after adding `AiUsage::organization()` and changing the alert test's token amounts.
- New tests are in `tests/Feature/Hardening/`: `AutoJoinTest`, `AuditTest`, `HardeningTest` and `EvaluationTest`.
- The full suite passes (202 tests), Larastan reports 0 errors and Pint is clean.
- **Bug fixed:** `AuditLog::changes()` recorded the new value as the old one, because `getOriginal()` is already synced after save. It now reads `getPrevious()`.
- Also added `AuditEvent::organization()`.

Still to do: tasks 4 (browser check), 5 (monitoring, which needs the user's approval) and 7 (commit, only when the user asks).

## Remaining tasks

1. **Fix the 3 failing tests in `tests/Feature/Hardening/UsageAndBudgetTest.php`:**
   - **Missing relation:** add an `organization()` BelongsTo relation to `AiUsage`. The factory's `->for($organization)` needs it.
   - **Budget alert test:** with a $1.00 budget, 400,000 input tokens on Sonnet 5.5 cost exactly $0.80. That already reaches 80%, so the alert fires on the first call. Change the first call to stay under 80%, for example 300,000 tokens, then cross 80% and 100%.
   - Run the file again and fix anything else it shows.
2. **Write the remaining Phase 6 tests:**
   - **Auto-join** (`tests/Feature/Hardening/AutoJoinTest.php`):
     - The verification link joins the matching organization with its role and switches the current organization.
     - An unverified user is not joined.
     - Signing in joins an organization whose domain was added later.
     - Public domains never match.
     - Settings validation rejects an invalid domain, a public provider, a domain claimed by another organization, and a domain other than the admin's own verified one; keeping an existing domain is still allowed.
     - Registration redirects to the verification page.
     - The verification page lists the organizations the user will join.
     - To fake the verification link, generate a signed URL with `URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)])`.
   - **Audit:**
     - Events are recorded for approving a learning, creating an agent, changing a member's role, and saving organization settings.
     - The audit page is forbidden to members, open to admins, and its search filters.
   - **Hardening:**
     - A GitHub 403 secondary rate limit, with `Retry-After: 1`, is retried and then succeeds. Use `Http::fakeSequence`, and keep the delay small or set `Sleep::fake()`.
     - `ReviewPullRequest` releases on `Laravel\Ai\Exceptions\RateLimitedException`: the run goes back to Queued. Use a real queued job, or check through a fake job instance with `withFakeQueueInteractions()` and `assertReleased()`.
     - `TransientFailure::backoff()` returns a delay for load failures and null for anything else.
     - `model:prune` deletes old webhook events and keeps recent ones.
   - **Eval** (`tests/Feature/Hardening/EvaluationTest.php`):
     - Use `CodeReviewer::fake(...)` with one correct finding for sql-injection.
     - Run `eval:run --case=sql-injection --force --json=<tmp>`, then check the output and JSON show 100% recall and that no `AiUsage` row was created.
3. **Run the full suite, Larastan and Pint.** Fix the Larastan errors; likely spots are the closures in `UsageReport`, `$organization->auto_join_role` (it can be null on a new model) and the `AuditEvent` morph types.
4. **Check in the browser** (dev server: `php8.5 artisan serve`, port 8000, in `.claude/launch.json`; run `npm run build` after changing Blade classes):
   - `/settings/usage`, `/settings/audit` and the Joining card on `/settings/organization`
   - the verify-email page
   - the Settings nav for an owner and for an admin
5. **Monitoring is not done.** Sentry and Pulse would be new dependencies, which the project rules say need the user's approval. Ask the user before adding either.
6. **Optional follow-ups:**
   - Seed demo usage in `DemoReviewSeeder` so the usage page has data.
   - Show a "budget reached" badge on pull request pages.
   - Add `eval:run` to CI, as an opt-in job because it costs money.
7. **Commit** on `phase-6-hardening` when the user asks, leaving out `DatabaseSeeder.php`.

## Things to know

- **Cost is an estimate.** It comes from token counts and the configured prices; prompt-cache discounts are not tracked, because `laravel/ai`'s `Usage` only reports input and output tokens. Models missing from `config/ai_pricing.php` are recorded at $0, and the usage page marks them with `*`.
- **Embeddings are not metered.** `Embedder` doesn't record usage because it has no organization at hand.
- **Budget alerts can repeat.** They use the cache to send each email once; if the cache is flushed, they could be sent again.
