<?php

namespace App\Ai\Agents\Concerns;

use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;

/**
 * Sends the organization's reasoning effort to providers that support it; others get no extra option.
 * Agents with tools also ask Anthropic to cache the conversation, which every tool step sends again.
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
        $provider = $provider instanceof Lab ? $provider->value : $provider;

        $options = blank($this->effort) ? [] : match ($provider) {
            'anthropic' => ['output_config' => ['effort' => $this->effort]],
            'openai', 'azure', 'openrouter' => ['reasoning' => ['effort' => $this->effort]],
            default => [],
        };

        if ($provider === 'anthropic' && $this instanceof HasTools && filled($this->tools())) {
            $options['cache_control'] = ['type' => 'ephemeral'];
        }

        return $options;
    }
}
