<?php

namespace Database\Factories;

use App\Models\ModelPrice;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModelPrice>
 */
class ModelPriceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'model' => 'vendor/model-'.fake()->unique()->word(),
            'input_per_million' => 1.5,
            'output_per_million' => 6,
        ];
    }
}
