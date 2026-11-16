<?php

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'risk_threshold' => 50,
            'auto_review_default' => true,
            'eco_mode' => false,
            'bot_handle' => 'donkey',
        ];
    }

    public function ecoMode(): static
    {
        return $this->state(['eco_mode' => true]);
    }

    /**
     * Attach a user with the given role and make it their current organization.
     */
    public function withMember(User $user, OrganizationRole $role = OrganizationRole::Owner): static
    {
        return $this->afterCreating(function (Organization $organization) use ($user, $role): void {
            $organization->addMember($user, $role);
            $user->switchOrganization($organization);
        });
    }
}
