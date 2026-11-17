<?php

namespace Database\Factories;

use App\Enums\FindingSeverity;
use App\Models\Repository;
use App\Models\SecurityAdvisory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityAdvisory>
 */
class SecurityAdvisoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory(),
            'package' => fake()->userName().'/'.fake()->slug(1),
            'installed_version' => '1.0.0',
            'is_dev' => false,
            'advisory_id' => 'PKSA-'.fake()->unique()->bothify('????-????-????'),
            'title' => fake()->sentence(),
            'cve' => null,
            'link' => fake()->url(),
            'severity' => fake()->randomElement(FindingSeverity::cases()),
            'affected_versions' => '<1.1.0',
            'reported_at' => now()->subWeek(),
        ];
    }
}
