# 🫏 Donkey

**An AI code reviewer for GitHub that learns how your team works.**

Donkey reviews pull requests the moment they open: it leaves inline comments with ready-to-commit suggestions, answers questions in the thread, and gets better every time someone tells it it was wrong. It runs as a GitHub App on your own infrastructure, with your own model API key.

## Features

- **Inline reviews with suggestions:** comments land on the exact lines, with `suggestion` blocks you can commit in one click, plus a check run on the commit.
- **Risk-score gate:** every finding gets a 0–100 risk score. Set a threshold per organization or per repository; lower-risk findings stay in the dashboard instead of cluttering the pull request.
- **Verifier pass:** a second AI pass re-reads each finding against the surrounding code and rejects false positives before anything is posted.
- **Code graph context:** with [kodus-graph](https://github.com/kodustech/kodus-graph), reviews know who calls a changed function, which signatures changed, how far the change spreads and what has no tests.
- **Reads your conventions:** `CLAUDE.md` and `AGENTS.md` from the repository root and from the directories of changed files.
- **Talk to it on GitHub:** `@donkey` answers questions, in a finding's thread or on the pull request.
- **Configurable agents:** specialist reviewers (Security, Laravel, Architecture, Testing, Performance, or your own) with their own instructions, files and severity floor. Run one on demand with `@donkey run security`, or let it join automatic reviews.
- **Memory and learning:** `@donkey remember …` teaches it about a repository. Dismissals, reactions and resolved threads become proposed learnings that an admin approves, scoped to one repository or the whole organization.
- **Teams and roles:** organizations with owners, admins, members and viewers, invitations, and optional auto-join for colleagues with a verified email at your domain.
- **Cost control:** every AI call is metered; owners see spend by feature, repository, model and pull request, and can set a monthly budget with email alerts.
- **Live dashboard:** review progress streams in real time over Laravel Reverb.
- **Audit log, retention and an evaluation harness** for running it seriously.

## Commands in pull request comments

| Comment | What it does |
|---|---|
| `@donkey review` | Review the changes since the last review |
| `@donkey full review` | Review the whole pull request again |
| `@donkey run <agent>` / `@donkey agents` | Run one specialist agent / list them |
| `@donkey <question>` | Ask anything about the pull request, or about a finding when asked in its thread |
| `@donkey ignore: <why>` | In a finding's thread: dismiss it and teach Donkey |
| `@donkey remember <fact>` | Save a fact about the repository for future reviews |
| `@donkey pause` / `@donkey resume` | Stop or restart automatic reviews on this pull request |
| `@donkey help` | List the commands |

`donkey` is the default handle; change it in the organization settings.

## Requirements

- PHP 8.5 with the `pgsql` and `redis` extensions, and [Composer](https://getcomposer.org)
- Node.js 20+ and npm
- PostgreSQL 16+ with the [pgvector](https://github.com/pgvector/pgvector) extension
- Redis
- A [GitHub App](#2-create-the-github-app) you own
- An API key for a model provider supported by [`laravel/ai`](https://laravel.com/docs/ai) (Anthropic by default; OpenRouter works with `AI_PROVIDER=openrouter`, `OPENROUTER_API_KEY` and `REVIEW_AI_MODEL`)

Docker Compose can provide PostgreSQL and Redis, or the whole stack (see [Deploy with Docker](#deploy-with-docker)).

## Install

Two ways to run Donkey: with Docker (recommended for a server, nothing to install but Docker) or natively for development.

### Deploy with Docker

The repository ships a production image (PHP-FPM, nginx, Horizon, Reverb and the scheduler, with Bun and `kodus-graph` included) and a Compose file that adds PostgreSQL with pgvector and Redis.

1. **Create the GitHub App** (see [step 2 below](#2-create-the-github-app)) and save its private key as `github-app.pem` next to `compose.prod.yaml`.
2. **Create the environment file** and fill it in:

   ```bash
   cp .env.production.example .env.production
   ```

   Set at least `APP_URL`, `DB_PASSWORD`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` (any random strings), the `GITHUB_*` values and `ANTHROPIC_API_KEY`. Generate `APP_KEY`:

   ```bash
   docker compose --env-file .env.production -f compose.prod.yaml run --rm --no-deps -e APP_KEY=x app php artisan key:generate --show
   ```

3. **Start it.** Images are built by GitHub Actions on every push to `main` and published to GHCR (`ghcr.io/<owner>/donkey-app` and `donkey-web`), so the server never builds anything:

   ```bash
   docker login ghcr.io   # only if the packages are private: a token with read:packages
   ./deploy.sh            # pull the latest images and restart; ./deploy.sh <commit-sha> rolls back
   ```

   To build on the server instead, run `docker compose --env-file .env.production -f compose.prod.yaml up -d --build`.

   Migrations run automatically before the app starts. The web container is reached through Traefik and also proxies WebSockets (`/app`) to Reverb, so one hostname is enough. Keep `TRUSTED_PROXIES=*`.

   The stack is wired for Traefik (external network `web`, entrypoint `websecure`, resolver `lets-encrypt`): set `DONKEY_HOST` in `.env.production` and no host port is published. Adjust the labels in `compose.prod.yaml` if your Traefik setup differs.

4. Open your `APP_URL`, register, create an organization and connect GitHub from **Repositories → Connect GitHub**.

To upgrade, pull the new code and run the same `up -d --build` command; migrations run again. Data lives in the `pgsql-data`, `redis-data` and `storage` volumes. Logs go to `docker compose logs`.

### Install for development

### 1. Get the code and the services

```bash
git clone <your-fork-or-this-repo-url> donkey
cd donkey
```

Start PostgreSQL (with pgvector) and Redis:

```bash
docker compose up -d pgsql redis
```

This exposes PostgreSQL on port `5434` and Redis on port `6380`.

### 2. Create the GitHub App

In GitHub, go to **Settings → Developer settings → GitHub Apps → New GitHub App** (for an organization, use the organization's settings).

| Setting | Value |
|---|---|
| Homepage URL | your `APP_URL` |
| Callback URL | `<APP_URL>/github/setup` (also set "Request user authorization (OAuth) during installation") |
| Setup URL | `<APP_URL>/github/setup` (also set "Redirect on update") |
| Webhook URL | `<APP_URL>/webhooks/github` |
| Webhook secret | a long random string |

During development, expose your machine with a tunnel (for example `ngrok http 8000`) and use its address as `APP_URL`; GitHub must be able to reach the webhook URL.

**Repository permissions:** Pull requests: read & write · Contents: read · Checks: read & write · Issues: read & write · Metadata: read.

**Subscribe to events:** Pull request · Issue comment · Pull request review comment · Pull request review thread · Installation and installation repositories.

Then generate a **private key** and save it as `storage/app/private/github-app.pem`.

### 3. Install and configure

```bash
composer setup
```

This installs PHP and JavaScript dependencies, creates `.env`, generates the app key, runs the migrations and builds the assets. It also installs [Bun](https://bun.sh) and `kodus-graph` locally (no global install, no `curl | bash`).

Then edit `.env`:

```dotenv
APP_URL=https://your-host

# PostgreSQL + Redis (the ports from docker compose)
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5434
DB_DATABASE=donkey
DB_USERNAME=donkey
DB_PASSWORD=donkey
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_PORT=6380

# GitHub App
GITHUB_APP_ID=
GITHUB_APP_SLUG=
GITHUB_APP_CLIENT_ID=
GITHUB_APP_CLIENT_SECRET=
GITHUB_APP_PRIVATE_KEY_PATH=storage/app/private/github-app.pem
GITHUB_WEBHOOK_SECRET=

# Model provider
ANTHROPIC_API_KEY=
```

Real-time updates use Laravel Reverb. Generate its credentials with `php artisan install:broadcasting` if your `.env` has no `REVERB_*` values, and set `BROADCAST_CONNECTION=reverb`.

Mail is only needed for invitations, email verification and budget alerts; set `MAIL_*` to a real mailer in production (the default `log` mailer writes emails to `storage/logs`).

> If `.env.example` and your database settings disagree, `.env` always wins. After pulling new code, run `php artisan migrate`.

### 4. Run it

```bash
composer dev
```

This starts the web server, Horizon (queues), Reverb (websockets) and the Vite dev server. Open <http://localhost:8000>, register, create an organization, and connect GitHub from **Repositories → Connect GitHub**.

Reviews also need the scheduler for the nightly jobs (learning proposals, decay and pruning):

```bash
php artisan schedule:work
```

## Configuration

| File | What it controls |
|---|---|
| `config/reviews.php` | Provider and model, size limits, ignored paths, verifier, conversation settings |
| `config/graph.php` | Code graph: enable/disable, binary location, timeouts |
| `config/agents.php` | Templates offered when creating an agent |
| `config/learning.php` | Memory and learning: embeddings, prompt budget, decay |
| `config/ai_pricing.php` | Price per million tokens used for cost estimates |
| `config/retention.php` | How long webhooks, usage, audit events and conversations are kept |
| `config/auto_join.php` | Public email providers that can never be auto-joined |

Useful environment variables: `REGISTRATION_ENABLED=false` (close sign-ups once your own account exists; people with a pending invitation can still register), `REVIEW_AI_MODEL`, `REVIEW_VERIFIER_ENABLED`, `GRAPH_ENABLED`, `LEARNING_EMBEDDINGS_PROVIDER` (set it, for example to `openai`, to enable similarity search over memories and learnings).

## Using Donkey

1. **Connect GitHub** and pick the repositories to review. Auto-review is on by default; turn it off per repository.
2. **Set the risk threshold** per organization (Settings) or per repository (Repositories). Higher means fewer, more important comments.
3. **Open a pull request.** Watch the review progress live on its page, and see findings, the impact analysis and the conversation.
4. **Teach it.** Dismiss wrong findings with `@donkey ignore: <why>`, add facts with `@donkey remember …`, then approve the proposed learnings in **Knowledge**.
5. **Add agents** in **Agents**, starting from a template.
6. **Watch the bill.** Owners see **Usage & cost** and can set a monthly budget; when it is reached, new reviews and answers pause until next month.

## Evaluating changes

Before changing a prompt or switching models, measure the effect on pull requests with known, planted problems:

```bash
php artisan eval:run
```

It reports recall (planted bugs found), precision (how much of what it said was right), severity accuracy and cost. Cases live in `tests/Evals/cases`. Each run calls the model, so it asks for confirmation first.

## Development

```bash
composer test                              # Pest
vendor/bin/pint --dirty --format agent     # code style
vendor/bin/phpstan analyse                 # Larastan
```

Tests never reach the network: every HTTP request must be faked. See [PHASES.md](PHASES.md) for how the project was built, phase by phase, and [PHASE6_HANDOFF.md](PHASE6_HANDOFF.md) for the work still open.

## Built with

[Laravel 13](https://laravel.com) · [Livewire 4](https://livewire.laravel.com) · [Tailwind CSS](https://tailwindcss.com) · [Alpine.js](https://alpinejs.dev) · [Laravel Reverb](https://reverb.laravel.com) · [Laravel Horizon](https://laravel.com/docs/horizon) · [laravel/ai](https://laravel.com/docs/ai) · [kodus-graph](https://github.com/kodustech/kodus-graph) · PostgreSQL + pgvector

## License

Add your license here.
