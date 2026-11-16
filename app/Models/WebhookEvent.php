<?php

namespace App\Models;

use App\Enums\WebhookEventStatus;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
}
