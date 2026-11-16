<?php

namespace App\Actions;

use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitation;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteMember
{
    public function handle(Organization $organization, User $inviter, string $email, OrganizationRole $role): Invitation
    {
        $email = Str::lower(trim($email));

        if ($organization->members()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'This person is already a member.']);
        }

        $organization->invitations()->pending()->where('email', $email)->delete();

        $invitation = $organization->invitations()->create([
            'invited_by_id' => $inviter->id,
            'email' => $email,
            'role' => $role,
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($email)->queue(new OrganizationInvitation($invitation));

        return $invitation;
    }
}
