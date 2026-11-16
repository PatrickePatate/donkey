<?php

namespace App\Events;

use App\Models\WebhookEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WebhookEventProcessed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $organizationId, public WebhookEvent $webhookEvent) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("organizations.{$this->organizationId}")];
    }

    public function broadcastAs(): string
    {
        return 'webhook.processed';
    }

    /**
     * Only send what the activity feed needs, never the raw payload.
     *
     * @return array{id: int, event: string, action: ?string, status: string}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->webhookEvent->id,
            'event' => $this->webhookEvent->event,
            'action' => $this->webhookEvent->action,
            'status' => $this->webhookEvent->status->value,
        ];
    }
}
