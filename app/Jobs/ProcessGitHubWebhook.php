<?php

namespace App\Jobs;

use App\Enums\WebhookEventStatus;
use App\Events\RepositoriesSynced;
use App\Events\WebhookEventProcessed;
use App\Models\GithubInstallation;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Routes a stored webhook delivery to the matching handler.
 */
class ProcessGitHubWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [5, 30, 120];

    public function __construct(public WebhookEvent $webhookEvent)
    {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        $installation = $this->webhookEvent->installation_id
            ? GithubInstallation::query()->firstWhere('installation_id', $this->webhookEvent->installation_id)
            : null;

        $status = match ($this->webhookEvent->event) {
            'ping' => WebhookEventStatus::Processed,
            'installation' => $this->handleInstallation($installation),
            'installation_repositories' => $this->handleInstallationRepositories($installation),
            default => WebhookEventStatus::Ignored,
        };

        $this->webhookEvent->markAs($status);

        if ($installation !== null && $installation->exists) {
            WebhookEventProcessed::dispatch($installation->organization_id, $this->webhookEvent);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->webhookEvent->markAs(WebhookEventStatus::Failed, $exception?->getMessage());
    }

    /**
     * Installations are linked to an organization by the setup callback; unknown ones are ignored here.
     */
    private function handleInstallation(?GithubInstallation $installation): WebhookEventStatus
    {
        if ($installation === null) {
            return WebhookEventStatus::Ignored;
        }

        match ($this->webhookEvent->action) {
            'deleted' => $installation->delete(),
            'suspend' => $installation->forceFill(['suspended_at' => now()])->save(),
            'unsuspend' => $installation->forceFill(['suspended_at' => null])->save(),
            'created', 'new_permissions_accepted' => SyncInstallationRepositories::dispatch($installation),
            default => null,
        };

        if ($this->webhookEvent->action === 'deleted') {
            RepositoriesSynced::dispatch($installation->organization_id);
        }

        return WebhookEventStatus::Processed;
    }

    private function handleInstallationRepositories(?GithubInstallation $installation): WebhookEventStatus
    {
        if ($installation === null) {
            return WebhookEventStatus::Ignored;
        }

        $payload = $this->webhookEvent->payload;

        foreach ($payload['repositories_added'] ?? [] as $repository) {
            $installation->repositories()->updateOrCreate(
                ['github_id' => $repository['id']],
                [
                    'organization_id' => $installation->organization_id,
                    'full_name' => $repository['full_name'],
                    'is_private' => (bool) ($repository['private'] ?? true),
                ],
            );
        }

        $removed = array_column($payload['repositories_removed'] ?? [], 'id');

        if ($removed !== []) {
            $installation->repositories()->whereIn('github_id', $removed)->delete();
        }

        $installation->forceFill(['repository_selection' => $payload['repository_selection'] ?? $installation->repository_selection])->save();

        RepositoriesSynced::dispatch($installation->organization_id);

        return WebhookEventStatus::Processed;
    }
}
