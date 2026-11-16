<?php

namespace App\Actions;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AcceptInvitation
{
    public function handle(Invitation $invitation, User $user): void
    {
        if (! $invitation->isPending()) {
            throw ValidationException::withMessages(['invitation' => 'This invitation has expired or was already used.']);
        }

        if (Str::lower($user->email) !== Str::lower($invitation->email)) {
            throw ValidationException::withMessages(['invitation' => 'This invitation was sent to a different email address.']);
        }

        DB::transaction(function () use ($invitation, $user): void {
            if (! $user->belongsToOrganization($invitation->organization)) {
                $invitation->organization->addMember($user, $invitation->role);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();
            $user->switchOrganization($invitation->organization);
        });
    }
}
