<?php

namespace App\Events;

use App\Models\ReviewRun;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast on every status or stage change so the dashboard can follow a review live.
 */
class ReviewRunUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ReviewRun $run) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('organizations.'.$this->run->pullRequest->repository->organization_id)];
    }

    public function broadcastAs(): string
    {
        return 'review.updated';
    }

    /**
     * @return array{id: int, pull_request_id: int, status: string, stage: ?string}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->run->id,
            'pull_request_id' => $this->run->pull_request_id,
            'status' => $this->run->status->value,
            'stage' => $this->run->stage?->value,
        ];
    }
}
