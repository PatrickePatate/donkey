<?php

namespace Database\Factories;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewFinding>
 */
class ReviewFindingFactory extends Factory
{
    public function definition(): array
    {
        $line = fake()->numberBetween(1, 200);

        return [
            'review_run_id' => ReviewRun::factory(),
            'pull_request_id' => fn (array $attributes): int => ReviewRun::find($attributes['review_run_id'])->pull_request_id,
            'fingerprint' => fake()->sha1(),
            'category' => FindingCategory::Bug,
            'severity' => FindingSeverity::High,
            'confidence' => 80,
            'risk_score' => 60,
            'file_path' => 'app/Services/Example.php',
            'start_line' => $line,
            'end_line' => $line,
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'status' => FindingStatus::Published,
        ];
    }
}
