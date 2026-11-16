<?php

namespace Database\Factories;

use App\Models\Repository;
use App\Models\RepositoryMemory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepositoryMemory>
 */
class RepositoryMemoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory(),
            'author_login' => fake()->userName(),
            'body' => fake()->sentence(),
            'path_glob' => null,
            'pinned' => false,
            'source' => RepositoryMemory::SOURCE_DASHBOARD,
        ];
    }

    public function pinned(): static
    {
        return $this->state(['pinned' => true]);
    }
}
