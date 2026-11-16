<?php

namespace Database\Factories;

use App\Models\Repository;
use App\Models\RepositoryDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepositoryDocument>
 */
class RepositoryDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory(),
            'commit_sha' => fake()->sha1(),
            'content' => fake()->paragraphs(3, true),
            'generated_at' => now(),
        ];
    }
}
