<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\ReviewAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewAgent>
 */
class ReviewAgentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'repository_id' => null,
            'slug' => 'security',
            'name' => 'Security',
            'description' => 'Looks for vulnerabilities.',
            'instructions' => 'Look for injection, broken access control and leaked secrets.',
            'model' => null,
            'file_globs' => null,
            'categories' => null,
            'severity_floor' => null,
            'run_on_auto_review' => false,
            'enabled' => true,
        ];
    }

    public function onAutoReview(): static
    {
        return $this->state(['run_on_auto_review' => true]);
    }
}
