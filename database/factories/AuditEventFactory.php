<?php

namespace Database\Factories;

use App\Models\AuditEvent;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'actor' => fake()->name(),
            'action' => 'organization.updated',
            'created_at' => now(),
        ];
    }
}
