<?php

namespace Database\Factories;

use App\Enums\LearningKind;
use App\Enums\LearningStatus;
use App\Models\Learning;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Learning>
 */
class LearningFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'repository_id' => null,
            'kind' => LearningKind::FalsePositive,
            'status' => LearningStatus::Active,
            'body' => 'Raw SQL in app/Reports is intentional and already reviewed for injection.',
            'rationale' => 'Dismissed twice.',
            'weight' => 1,
        ];
    }

    public function proposed(): static
    {
        return $this->state(['status' => LearningStatus::Proposed]);
    }
}
