<?php

namespace App\Services\GitHub\Webhooks;

use App\Enums\FindingStatus;
use App\Enums\SignalType;
use App\Enums\WebhookEventStatus;
use App\Models\GithubInstallation;
use App\Models\ReviewFinding;
use App\Models\WebhookEvent;
use App\Services\Knowledge\SignalRecorder;

/**
 * Resolving the thread of one of the bot's findings counts as positive feedback on it.
 */
class HandleReviewThreadEvent
{
    public function __construct(private SignalRecorder $signals) {}

    public function handle(?GithubInstallation $installation, WebhookEvent $event): WebhookEventStatus
    {
        $payload = $event->payload;
        $rootCommentId = $payload['thread']['comments'][0]['id'] ?? null;

        if ($event->action !== 'resolved' || $installation === null || ! is_int($rootCommentId)) {
            return WebhookEventStatus::Ignored;
        }

        $finding = ReviewFinding::query()
            ->where('github_comment_id', $rootCommentId)
            ->where('status', '!=', FindingStatus::Dismissed)
            ->whereHas('pullRequest.repository', fn ($query) => $query->where('github_installation_id', $installation->id))
            ->latest('id')
            ->first();

        if ($finding === null) {
            return WebhookEventStatus::Ignored;
        }

        $this->signals->record($finding, SignalType::Resolved, $payload['sender']['login'] ?? null);

        return WebhookEventStatus::Processed;
    }
}
