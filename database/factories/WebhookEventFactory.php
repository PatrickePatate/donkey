<?php

namespace Database\Factories;

use App\Enums\WebhookEventStatus;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'delivery_id' => fake()->uuid(),
            'event' => 'ping',
            'action' => null,
            'payload' => ['zen' => 'Keep it logically awesome.'],
            'status' => WebhookEventStatus::Pending,
        ];
    }
}
