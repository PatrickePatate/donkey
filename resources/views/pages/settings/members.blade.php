<?php

use App\Actions\InviteMember;
use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Members')] class extends Component
{
    public string $inviteEmail = '';

    public string $inviteRole = 'member';

    #[Computed]
    public function organization(): Organization
    {
        return auth()->user()->currentOrganization;
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->organization->members()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Invitation>
     */
    #[Computed]
    public function invitations(): Collection
    {
        return $this->organization->invitations()->pending()->latest()->get();
    }

    public function invite(InviteMember $inviteMember): void
    {
        Gate::authorize('manageMembers', $this->organization);

        $this->validate([
            'inviteEmail' => ['required', 'email', 'max:255'],
            'inviteRole' => ['required', Rule::enum(OrganizationRole::class)->only(OrganizationRole::assignable())],
        ]);

        $inviteMember->handle($this->organization, auth()->user(), $this->inviteEmail, OrganizationRole::from($this->inviteRole));

        $this->dispatch('toast', message: "Invitation sent to {$this->inviteEmail}.", variant: 'success');
        $this->dispatch('close-dialog');
        $this->reset('inviteEmail', 'inviteRole');
    }

    public function changeRole(int $userId, string $role): void
    {
        Gate::authorize('manageMembers', $this->organization);

        $member = $this->organization->members()->findOrFail($userId);
        $newRole = OrganizationRole::tryFrom($role);
        $currentRole = $member->pivot->role;
        $actorRole = auth()->user()->roleIn($this->organization);

        if ($newRole === null) {
            return;
        }

        if (! $this->canManage($actorRole, $currentRole) || ! $actorRole->isAtLeast($newRole)) {
            $this->dispatch('toast', message: "You can't assign that role.", variant: 'destructive');

            return;
        }

        if ($currentRole === OrganizationRole::Owner && $newRole !== OrganizationRole::Owner && $this->organization->ownersCount() === 1) {
            $this->dispatch('toast', message: 'An organization needs at least one owner.', variant: 'destructive');

            return;
        }

        $this->organization->members()->updateExistingPivot($member->id, ['role' => $newRole->value]);
        $this->dispatch('toast', message: "{$member->name} is now {$newRole->label()}.", variant: 'success');
    }

    public function removeMember(int $userId): void
    {
        $member = $this->organization->members()->findOrFail($userId);
        $isSelf = $member->is(auth()->user());

        if (! $isSelf) {
            Gate::authorize('manageMembers', $this->organization);

            if (! $this->canManage(auth()->user()->roleIn($this->organization), $member->pivot->role)) {
                $this->dispatch('toast', message: "You can't remove this member.", variant: 'destructive');

                return;
            }
        }

        if ($member->pivot->role === OrganizationRole::Owner && $this->organization->ownersCount() === 1) {
            $this->dispatch('toast', message: 'Transfer ownership before removing the last owner.', variant: 'destructive');

            return;
        }

        $this->organization->members()->detach($member->id);

        if ($member->current_organization_id === $this->organization->id) {
            $member->forceFill(['current_organization_id' => null])->save();
        }

        if ($isSelf) {
            $this->redirectRoute('dashboard');

            return;
        }

        $this->dispatch('toast', message: "{$member->name} was removed.", variant: 'success');
    }

    public function revokeInvitation(int $invitationId): void
    {
        Gate::authorize('manageMembers', $this->organization);

        $this->organization->invitations()->whereKey($invitationId)->delete();
        $this->dispatch('toast', message: 'Invitation revoked.');
    }

    /**
     * Admins manage roles below Owner; owners manage everyone.
     */
    private function canManage(?OrganizationRole $actor, OrganizationRole $target): bool
    {
        return $actor === OrganizationRole::Owner || ($actor?->isAtLeast(OrganizationRole::Admin) && $target !== OrganizationRole::Owner);
    }
};
?>

<div class="grid gap-6">
    @php($canManage = auth()->user()->can('manageMembers', $this->organization))
    @php($actorRole = auth()->user()->roleIn($this->organization))

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="grid gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">Members</h1>
            <p class="text-sm text-muted-foreground">People who can access {{ $this->organization->name }}.</p>
        </div>

        @if ($canManage)
            <x-ui.button x-on:click="$dispatch('open-dialog', 'invite-member')">
                <x-ui.icon name="plus" /> Invite member
            </x-ui.button>
        @endif
    </div>

    <x-ui.card>
        <ul class="divide-y">
            @foreach ($this->members as $member)
                @php($role = $member->pivot->role)
                <li wire:key="member-{{ $member->id }}" class="flex flex-wrap items-center gap-3 py-3">
                    <x-ui.avatar :initials="$member->initials()" />
                    <div class="grid flex-1 text-sm">
                        <span class="font-medium">
                            {{ $member->name }}
                            @if ($member->is(auth()->user()))
                                <span class="text-muted-foreground">(you)</span>
                            @endif
                        </span>
                        <span class="text-muted-foreground">{{ $member->email }}</span>
                    </div>

                    @if ($canManage && ! $member->is(auth()->user()) && ($actorRole === \App\Enums\OrganizationRole::Owner || $role !== \App\Enums\OrganizationRole::Owner))
                        <x-ui.select class="w-32" wire:change="changeRole({{ $member->id }}, $event.target.value)">
                            @foreach (\App\Enums\OrganizationRole::cases() as $option)
                                @if ($actorRole->isAtLeast($option))
                                    <option value="{{ $option->value }}" @selected($option === $role)>{{ $option->label() }}</option>
                                @endif
                            @endforeach
                        </x-ui.select>
                        <x-ui.button variant="ghost" size="icon" wire:click="removeMember({{ $member->id }})" wire:confirm="Remove {{ $member->name }} from the organization?" title="Remove">
                            <x-ui.icon name="trash" />
                        </x-ui.button>
                    @else
                        <x-ui.badge variant="secondary">{{ $role->label() }}</x-ui.badge>
                        @if ($member->is(auth()->user()) && $this->members->count() > 1)
                            <x-ui.button variant="ghost" size="sm" wire:click="removeMember({{ $member->id }})" wire:confirm="Leave {{ $this->organization->name }}?">Leave</x-ui.button>
                        @endif
                    @endif
                </li>
            @endforeach
        </ul>
    </x-ui.card>

    @if ($canManage && $this->invitations->isNotEmpty())
        <x-ui.card title="Pending invitations">
            <ul class="divide-y">
                @foreach ($this->invitations as $invitation)
                    <li wire:key="invitation-{{ $invitation->id }}" class="flex items-center gap-3 py-3 text-sm">
                        <x-ui.icon name="mail" class="text-muted-foreground" />
                        <span class="flex-1">{{ $invitation->email }}</span>
                        <x-ui.badge variant="outline">{{ $invitation->role->label() }}</x-ui.badge>
                        <span class="text-xs text-muted-foreground">expires {{ $invitation->expires_at->diffForHumans() }}</span>
                        <x-ui.button variant="ghost" size="sm" wire:click="revokeInvitation({{ $invitation->id }})">Revoke</x-ui.button>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    @if ($canManage)
        <x-ui.dialog name="invite-member" title="Invite a member" description="They'll get an email with a link to join.">
            <form wire:submit="invite" class="grid gap-4">
                <x-ui.field label="Email" for="inviteEmail" error="inviteEmail">
                    <x-ui.input id="inviteEmail" type="email" wire:model="inviteEmail" required />
                </x-ui.field>

                <x-ui.field label="Role" for="inviteRole" error="inviteRole">
                    <x-ui.select id="inviteRole" wire:model="inviteRole">
                        @foreach (\App\Enums\OrganizationRole::assignable() as $option)
                            @if ($actorRole->isAtLeast($option))
                                <option value="{{ $option->value }}">{{ $option->label() }} — {{ $option->description() }}</option>
                            @endif
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <div class="flex justify-end gap-2">
                    <x-ui.button variant="outline" x-on:click="$dispatch('close-dialog')">Cancel</x-ui.button>
                    <x-ui.button type="submit">Send invitation</x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endif
</div>
