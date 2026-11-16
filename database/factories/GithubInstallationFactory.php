<?php

namespace Database\Factories;

use App\Models\GithubInstallation;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GithubInstallation>
 */
class GithubInstallationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'installation_id' => fake()->unique()->numberBetween(1_000_000, 99_999_999),
            'account_id' => fake()->numberBetween(1_000, 9_999_999),
            'account_login' => fake()->unique()->userName(),
            'account_type' => 'Organization',
            'repository_selection' => 'selected',
        ];
    }
}
