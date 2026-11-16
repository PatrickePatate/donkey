<?php

namespace Database\Factories;

use App\Enums\ReviewRunStatus;
use App\Enums\ReviewTrigger;
use App\Models\PullRequest;
use App\Models\ReviewRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReviewRun>
 */
class ReviewRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pull_request_id' => PullRequest::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'head_sha' => fake()->sha1(),
            'trigger' => ReviewTrigger::Auto,
            'status' => ReviewRunStatus::Queued,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => ReviewRunStatus::Completed,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }
}
