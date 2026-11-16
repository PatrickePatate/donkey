# Donkey: development phases

Donkey is an AI code reviewer for GitHub, an alternative to CodeRabbit and Kodus. This file records what each phase built and where to find it, so the work can be picked up by another developer or agent. Phase 6 is still in progress; see [PHASE6_HANDOFF.md](PHASE6_HANDOFF.md).

## Stack and conventions

- **Framework:** Laravel 13 on PHP 8.5. Always run `php8.5`, because plain `php` is 8.4.
- **UI:**
  - Livewire 4 single-file pages, in `resources/views/pages`, registered with `Route::livewire`.
  - Tailwind 4 with the Typography plugin, and Alpine.
  - shadcn-style Blade components in `resources/views/components/ui/*`.
- **AI:** `laravel/ai` calls the models. Agents live in `app/Ai/Agents`. Defaults are Anthropic as the provider and `claude-sonnet-5-5` as the model.
- **Realtime:** Reverb, on the private channel `organizations.{id}`.
- **Queues:** Horizon runs these queues:
  - `webhooks`
  - `github`
  - `conversations`
  - `reviews`
  - `graphs`
  - `default`
- **Database:**
  - Postgres with pgvector, in Docker on port 5434.
  - Redis, in Docker on port 6380.
  - The app itself runs natively.
- **Quality:** Pest, Larastan (`php8.5 vendor/bin/phpstan analyse --memory-limit=1G`) and Pint (`vendor/bin/pint --dirty --format agent`).
  - Tests block real HTTP requests with `Http::preventStrayRequests()`, so every request in a test must be faked.
- **Code graph:** `kodus-graph` 0.3.3 and Bun are installed as npm devDependencies; `composer setup` runs `npm run graph:setup`.
- **Branches:** every phase builds on the one before:

  ```text
  main → phase-3-context → phase-4-conversation → phase-5-learning → phase-6-hardening
  ```

  Phases 1 and 2 were committed directly before these branches. Nothing is merged into `main` yet.

## Phase 1: foundations (`8f38f13`)

**Accounts and organizations**
- Registration, login, organizations, and roles (owner, admin, member, viewer, defined in the `OrganizationRole` enum).
- Invitations by email.
- Permission checks in `app/Policies`.
- The `EnsureCurrentOrganization` middleware ensures every page has a current organization.

**GitHub App**
- `GitHubApp` signs its own tokens and caches installation tokens.
- The install and setup controllers check that the request is the one the user started and that the user's GitHub account can access the installation.
- Webhooks are checked against GitHub's signature, stored, and processed once each even if GitHub sends them again (`ProcessGitHubWebhook`).
- Repositories are synced from GitHub (`SyncInstallationRepositories`).

**Pages**
- Layout, dashboard with live activity, repositories, organization settings, and members.
- Auto-review can be turned on per repository, and the risk threshold set per repository, falling back to the organization's defaults.

## Phase 2: review engine (`f430ada`)

**Review pipeline**
- `ReviewPullRequest` runs these stages in order: Collect → Map impact → Analyze → Validate → Publish.
- A run never overlaps another run on the same pull request, and it stops when newer commits are pushed.
- `StartReview` makes sure the same review isn't started twice.

**What the reviewer sees and returns**
- The diff, with each line labelled with its new line number (`FilePatch`), split into chunks for large pull requests.
- `CLAUDE.md` and `AGENTS.md` from the repository root and from each changed file's directories.
- Findings in a strict format, from the `CodeReviewer` agent.

**Validation**
- Findings must point at real lines in the diff.
- Each finding gets a fingerprint, so the same issue isn't posted again on later reviews.
- Risk score = severity × confidence. Findings below the threshold are kept in the dashboard but not posted.

**Publishing on GitHub**
- One GitHub review with inline comments and `suggestion` blocks. If GitHub rejects the inline comments, the findings go in the summary instead.
- A check run on the commit.
- Reviews are triggered automatically, by `@donkey review` or `@donkey full review`, or from the dashboard.

**Pull request pages**
- Pull request list and detail pages, with a live step-by-step progress view (`x-ui.stepper`).
- `DemoReviewSeeder` fills the app with demo data.

## Phase 3: context and quality (`c8e9561`)

**Code graph**
- The kodus-graph integration lives in `app/Services/Graph`.
- A graph of each repository's default branch is built in a shallow checkout, and updated incrementally after merges.
- For each pull request it reports:
  - changed functions and their callers
  - signature changes
  - how much code is affected
  - functions without tests
- Findings in functions with many callers get a higher risk score.

**Verifier pass**
- A second AI pass (`FindingVerifier`) re-checks each finding against the surrounding code and rejects or re-rates it.

**UI and setup**
- An Impact card on the pull request page, and a graph status column on the Repositories page.
- Bun and kodus-graph install with the project.

## Phase 4: conversations and agents (`86606bd`)

**Commands in GitHub comments** (parsed by `ReviewCommand`)

| Command | What it does |
|---|---|
| `review`, `full review` | Starts a review |
| `run <agent>`, `agents` | Runs one agent, or lists them |
| `ignore` / `false positive` / `dismiss` | Dismisses a finding, in its thread |
| `pause`, `resume` | Turns automatic reviews off or on for one pull request |
| `help` | Lists the commands |
| any other text | Treated as a question |

- `BotCommandHandler` checks the comment author's access before acting.
- `remember` was added in Phase 5.

**Answering questions**
- Questions are answered in the background by `AnswerPullRequestComment`, using the `PullRequestAssistant` agent.
- Answers are posted where the question was asked: in the finding's thread, or in the pull request conversation.
- Context sent with the question: the pull request, the latest review, the finding, the thread, and the diff.

**Review agents**
- Specialist reviewers stored in the `review_agents` table, for the whole organization or one repository.
- Templates in `config/agents.php`.
- Each agent has its own file patterns, categories, minimum severity and model.
- An agent runs on demand, or joins automatic reviews.
- Test runs post nothing on GitHub.

**Pages**
- An Agents page and an agent editor.
- On the pull request page: a "Run agent" menu, a pause button, and a conversation card.

## Phase 5: memory and learning (`c762f16`)

**Memories**
- Facts about a repository, stored in `repository_memories`.
- Added with `@donkey remember …` on GitHub or on the Knowledge page. They can be pinned or limited to some files.
- Similarity search with pgvector is optional: set `LEARNING_EMBEDDINGS_PROVIDER` to turn it on.

**Feedback signals** (stored in `finding_signals`)
- Dismissals, with their reason.
- 👍 and 👎 reactions, read when the pull request closes.
- Resolved threads, from the `pull_request_review_thread` webhook.
- Suggestions applied or code fixed.
- Findings left untouched at merge.

**Learnings**
- The `LearningSynthesizer` agent turns negative feedback into proposed learnings, for one repository or the whole organization.
- Admins approve, edit or reject them in the Knowledge inbox.
- The reviewer and the verifier both get the approved learnings. When the verifier matches a finding to one, the finding is stopped and the learning gets more weight.
- Learnings that stop matching lose weight daily (`learnings:decay`) and are archived when too weak.

**Dashboard**
- A "Review quality" card: how many findings were accepted or rejected, by category and by reviewer.

## Phase 6: hardening (in progress)

Budgets and cost monitoring for owners, auto-join by verified email domain, the audit log, rate-limit retries, retention and the `eval:run` harness are built but not finished. The details and remaining tasks are in [PHASE6_HANDOFF.md](PHASE6_HANDOFF.md).

## Setup reminders

- **Running the app:**
  - `composer setup` installs everything.
  - Background services: `php8.5 artisan horizon`, `php8.5 artisan reverb:start` and `php8.5 artisan schedule:work`.
- **GitHub App webhook events** to subscribe to:
  - Pull request
  - Issue comment
  - Pull request review comment
  - Pull request review thread
  - Installation and installation repositories
- **No AI key yet:** `.env` currently has no AI provider key. Set `ANTHROPIC_API_KEY` before running real reviews.
