<?php

namespace App\Events;

use App\Models\PullRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a conversation with the bot changes on a pull request: a question, an answer, a dismissal.
 */
class ConversationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public PullRequest $pullRequest) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('organizations.'.$this->pullRequest->repository->organization_id)];
    }

    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }

    /**
     * @return array{pull_request_id: int}
     */
    public function broadcastWith(): array
    {
        return ['pull_request_id' => $this->pullRequest->id];
    }
}
