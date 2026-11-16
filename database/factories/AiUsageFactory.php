<?php

namespace Database\Factories;

use App\Enums\AiFeature;
use App\Models\AiUsage;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsage>
 */
class AiUsageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'feature' => AiFeature::Review,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'input_tokens' => 10_000,
            'output_tokens' => 1_000,
            'cost_micros' => 30_000,
            'created_at' => now(),
        ];
    }
}
