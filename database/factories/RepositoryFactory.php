<?php

namespace Database\Factories;

use App\Models\GithubInstallation;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repository>
 */
class RepositoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'github_installation_id' => GithubInstallation::factory(),
            'organization_id' => fn (array $attributes): int => GithubInstallation::find($attributes['github_installation_id'])->organization_id,
            'github_id' => fake()->unique()->numberBetween(1_000_000, 999_999_999),
            'full_name' => fake()->userName().'/'.fake()->unique()->slug(2),
            'default_branch' => 'main',
            'is_private' => true,
        ];
    }
}
