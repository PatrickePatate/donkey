<?php

namespace App\Actions;

use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Adds a user with a verified email address to every organization that auto-joins its domain.
 */
class AutoJoinOrganizations
{
    public function __construct(private AuditLog $audit) {}

    /**
     * @return Collection<int, Organization> The organizations the user just joined.
     */
    public function handle(User $user): Collection
    {
        if (! $user->hasVerifiedEmail()) {
            return new Collection;
        }

        $joined = self::organizationsFor($user->email)
            ->reject(fn (Organization $organization): bool => $user->belongsToOrganization($organization))
            ->each(function (Organization $organization) use ($user): void {
                $organization->addMember($user, $organization->auto_join_role);
                $this->audit->record($organization, 'member.auto_joined', $user, ['email' => $user->email, 'role' => $organization->auto_join_role->value], actor: $user->name);
            })
            ->values();

        if ($joined->isNotEmpty() && $user->current_organization_id === null) {
            $user->switchOrganization($joined->first());
        }

        return $joined;
    }

    /**
     * Organizations that auto-join an email address's domain.
     *
     * @return Collection<int, Organization>
     */
    public static function organizationsFor(string $email): Collection
    {
        $domain = Str::lower(Str::afterLast($email, '@'));

        if ($domain === '' || in_array($domain, config('auto_join.public_domains'), true)) {
            return new Collection;
        }

        return Organization::query()->whereJsonContains('auto_join_domains', $domain)->get();
    }
}
