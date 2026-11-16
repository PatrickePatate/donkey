<?php

namespace Database\Factories;

use App\Enums\ConversationRole;
use App\Enums\ConversationStatus;
use App\Models\ConversationMessage;
use App\Models\PullRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationMessage>
 */
class ConversationMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pull_request_id' => PullRequest::factory(),
            'github_comment_id' => fake()->unique()->numberBetween(100_000, 999_999),
            'role' => ConversationRole::User,
            'author_login' => fake()->userName(),
            'body' => fake()->sentence(),
            'status' => ConversationStatus::Answered,
        ];
    }

    public function fromBot(): static
    {
        return $this->state(['role' => ConversationRole::Bot, 'author_login' => 'donkey']);
    }
}
