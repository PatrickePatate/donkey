<?php

namespace App\Ai\Agents\Concerns;

use Laravel\Ai\Enums\Lab;

/**
 * Sends the organization's reasoning effort to providers that support it; others get no extra option.
 */
trait HasEffort
{
    public ?string $effort = null;

    public function withEffort(?string $effort): static
    {
        $this->effort = $effort;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        if (blank($this->effort)) {
            return [];
        }

        return match ($provider instanceof Lab ? $provider->value : $provider) {
            'anthropic' => ['output_config' => ['effort' => $this->effort]],
            'openai', 'azure', 'openrouter' => ['reasoning' => ['effort' => $this->effort]],
            default => [],
        };
    }
}
