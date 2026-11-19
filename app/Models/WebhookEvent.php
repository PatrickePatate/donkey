<?php

namespace App\Models;

use App\Enums\WebhookEventStatus;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $delivery_id
 * @property string $event
 * @property ?string $action
 * @property ?int $installation_id
 * @property array<string, mixed> $payload
 * @property WebhookEventStatus $status
 * @property ?string $error
 * @property ?Carbon $processed_at
 */
#[Fillable(['delivery_id', 'event', 'action', 'installation_id', 'payload', 'status', 'error', 'processed_at'])]
class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use HasFactory;
    use MassPrunable;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => WebhookEventStatus::class,
            'installation_id' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function markAs(WebhookEventStatus $status, ?string $error = null): void
    {
        $this->forceFill([
            'status' => $status,
            'error' => $error,
            'processed_at' => now(),
        ])->save();
    }

    /**
     * How the event reads in the activity feed; the tone tells at a glance whether it went well.
     *
     * @return array{icon: string, tone: 'success'|'danger'|'warning'|'info'|'neutral', title: string, subject: ?string}
     */
    public function activity(): array
    {
        $repository = data_get($this->payload, 'repository.full_name');
        $number = data_get($this->payload, 'pull_request.number') ?? data_get($this->payload, 'issue.number');
        $subject = $repository !== null && $number !== null ? "{$repository}#{$number}" : $repository;

        if ($this->status === WebhookEventStatus::Failed) {
            return ['icon' => 'triangle-alert', 'tone' => 'danger', 'title' => 'Webhook failed', 'subject' => $subject ?? $this->event];
        }

        [$icon, $tone, $title] = match ("{$this->event}.{$this->action}") {
            'pull_request.opened', 'pull_request.reopened', 'pull_request.ready_for_review' => ['git-pull-request', 'info', 'Pull request opened'],
            'pull_request.synchronize' => ['git-commit', 'neutral', 'New commits pushed'],
            'pull_request.closed' => data_get($this->payload, 'pull_request.merged')
                ? ['git-merge', 'success', 'Merged']
                : ['x', 'neutral', 'Closed'],
            'issue_comment.created', 'pull_request_review_comment.created' => ['message-square', 'info', 'Comment'],
            'pull_request_review_thread.resolved' => ['circle-check', 'success', 'Thread resolved'],
            'pull_request_review_thread.unresolved' => ['message-square', 'warning', 'Thread reopened'],
            'installation.created' => ['github', 'success', 'GitHub connected'],
            'installation.deleted', 'installation.suspend' => ['github', 'danger', 'GitHub disconnected'],
            'installation_repositories.added', 'installation_repositories.removed' => ['refresh', 'neutral', 'Repositories synced'],
            default => ['activity', 'neutral', str($this->event)->replace('_', ' ')->ucfirst()->append($this->action ? " {$this->action}" : '')->toString()],
        };

        return ['icon' => $icon, 'tone' => $tone, 'title' => $title, 'subject' => $subject];
    }

    /**
     * Old records are deleted by the scheduled "model:prune"; see config/retention.php.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('retention.webhook_event_days')));
    }
}
