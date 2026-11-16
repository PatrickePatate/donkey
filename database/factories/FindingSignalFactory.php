<?php

namespace Database\Factories;

use App\Enums\SignalType;
use App\Models\FindingSignal;
use App\Models\ReviewFinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FindingSignal>
 */
class FindingSignalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'review_finding_id' => ReviewFinding::factory(),
            'repository_id' => fn (array $attributes): int => ReviewFinding::find($attributes['review_finding_id'])->pullRequest->repository_id,
            'type' => SignalType::Dismissed,
            'actor_login' => fake()->userName(),
        ];
    }
}
