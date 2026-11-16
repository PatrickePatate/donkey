<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes sure the user works inside an organization they belong to, or sends them to create one.
 */
class EnsureCurrentOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $current = $user->currentOrganization;

        if ($current === null || ! $user->belongsToOrganization($current)) {
            $fallback = $user->organizations()->oldest('organization_user.created_at')->first();

            if ($fallback === null) {
                $user->forceFill(['current_organization_id' => null])->save();

                return redirect()->route('organizations.create');
            }

            $user->switchOrganization($fallback);
        }

        return $next($request);
    }
}
