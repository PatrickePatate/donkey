<?php

namespace Database\Factories;

use App\Models\PullRequest;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PullRequest>
 */
class PullRequestFactory extends Factory
{
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 99_999);

        return [
            'repository_id' => Repository::factory(),
            'github_id' => fake()->unique()->numberBetween(1_000_000, 999_999_999),
            'number' => $number,
            'title' => fake()->sentence(5),
            'body' => fake()->paragraph(),
            'author_login' => fake()->userName(),
            'state' => 'open',
            'is_draft' => false,
            'head_sha' => fake()->sha1(),
            'base_sha' => fake()->sha1(),
            'head_ref' => 'feature/'.fake()->slug(2),
            'base_ref' => 'main',
            'html_url' => "https://github.com/acme/app/pull/{$number}",
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['is_draft' => true]);
    }
}
