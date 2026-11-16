<?php

namespace App\Services\Ai;

use App\Models\Organization;
use Laravel\Ai\AiManager;

/**
 * Builds the laravel/ai provider an organization configured, with its own API key.
 */
class OrganizationAi
{
    /**
     * Call right before prompting: on-demand providers only live for the current job or request.
     *
     * @return string The provider name to pass to prompt().
     */
    public function provider(Organization $organization): string
    {
        $driver = $organization->aiProvider();

        return app(AiManager::class)->build([
            ...config("ai.providers.{$driver}", ['driver' => $driver]),
            'key' => $organization->ai_api_key,
            'name' => "organization-{$organization->id}",
        ])->name();
    }
}
