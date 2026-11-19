<?php

use App\Actions\InviteMember;
use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Members')] class extends Component
{
    use WithPagination;

    public string $inviteEmail = '';

    public string $inviteRole = 'member';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $role = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function roleCounts(): array
    {
        return $this->organization->members()
            ->toBase()
            ->selectRaw('organization_user.role, count(*) as aggregate')
            ->groupBy('organization_user.role')
            ->pluck('aggregate', 'role')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    #[Computed]
    public function organization(): Organization
    {
        return auth()->user()->currentOrganization;
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function members(): LengthAwarePaginator
    {
        return $this->organization->members()
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('users.name', "%{$this->search}%")
                ->orWhereLike('users.email', "%{$this->search}%")))
            ->when(OrganizationRole::tryFrom($this->role), fn ($query, $role) => $query->where('organization_user.role', $role->value))
            ->orderBy('name')
            ->paginate(25);
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
        app(\App\Services\Audit\AuditLog::class)->record($this->organization, 'member.invited', metadata: ['email' => $this->inviteEmail, 'role' => $this->inviteRole]);

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

        if (!$this->canManage($actorRole, $currentRole) || !$actorRole->isAtLeast($newRole)) {
            $this->dispatch('toast', message: "You can't assign that role.", variant: 'destructive');

            return;
        }

        if ($currentRole === OrganizationRole::Owner && $newRole !== OrganizationRole::Owner && $this->organization->ownersCount() === 1) {
            $this->dispatch('toast', message: 'An organization needs at least one owner.', variant: 'destructive');

            return;
        }

        $this->organization->members()->updateExistingPivot($member->id, ['role' => $newRole->value]);
        app(\App\Services\Audit\AuditLog::class)->record($this->organization, 'member.role_changed', $member, ['role' => [$currentRole->value, $newRole->value]]);
        $this->dispatch('toast', message: "{$member->name} is now {$newRole->label()}.", variant: 'success');
    }

    public function removeMember(int $userId): void
    {
        $member = $this->organization->members()->findOrFail($userId);
        $isSelf = $member->is(auth()->user());

        if (!$isSelf) {
            Gate::authorize('manageMembers', $this->organization);

            if (!$this->canManage(auth()->user()->roleIn($this->organization), $member->pivot->role)) {
                $this->dispatch('toast', message: "You can't remove this member.", variant: 'destructive');

                return;
            }
        }

        if ($member->pivot->role === OrganizationRole::Owner && $this->organization->ownersCount() === 1) {
            $this->dispatch('toast', message: 'Transfer ownership before removing the last owner.', variant: 'destructive');

            return;
        }

        $this->organization->members()->detach($member->id);
        app(\App\Services\Audit\AuditLog::class)->record($this->organization, $isSelf ? 'member.left' : 'member.removed', $member, ['email' => $member->email]);

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
        app(\App\Services\Audit\AuditLog::class)->record($this->organization, 'member.invitation_revoked', metadata: ['invitation_id' => $invitationId]);
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

<div class="grid gap-6" x-init="if (new URLSearchParams(location.search).has('invite')) $nextTick(() => $dispatch('open-dialog', 'invite-member'))">
    @php($canManage = auth()->user()->can('manageMembers', $this->organization))
    @php($actorRole = auth()->user()->roleIn($this->organization))
    @php($total = array_sum($this->roleCounts))

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="grid gap-1">
            <h1 class="text-2xl tracking-tight">Members</h1>
            <p class="text-sm text-muted-foreground">{{ $total }} {{ Str::plural('person', $total) }} can access {{ $this->organization->name }}.</p>
        </div>

        @if ($canManage)
            <x-ui.button x-on:click="$dispatch('open-dialog', 'invite-member')">
                <x-ui.icon name="user-plus" /> Invite member
            </x-ui.button>
        @endif
    </div>

    <div class="table-wrap">
        <div class="table-toolbar">
            <div class="flex flex-wrap gap-1 text-sm">
                @foreach (['' => 'All'] + collect(OrganizationRole::cases())->mapWithKeys(fn ($case) => [$case->value => Str::plural($case->label())])->all() as $value => $label)
                    @php($count = $value === '' ? $total : ($this->roleCounts[$value] ?? 0))
                    @if ($value === '' || $count > 0)
                        <button type="button" wire:click="$set('role', '{{ $value }}')" @class(['flex items-center gap-1.5 rounded-md px-2.5 py-1 transition-colors', 'bg-accent font-medium text-foreground' => $role === $value, 'text-muted-foreground hover:text-foreground' => $role !== $value])>
                            {{ $label }} <span class="text-xs tabular-nums text-muted-foreground">{{ $count }}</span>
                        </button>
                    @endif
                @endforeach
            </div>
            <label class="table-search">
                <x-ui.icon name="search" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search by name or email…" aria-label="Search members">
            </label>
        </div>

        @if ($this->members->isEmpty())
            <p class="py-10 text-center text-sm text-muted-foreground">No members match "{{ $search }}".</p>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Role</th>
                            <th>Joined</th>
                            <th><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->members as $member)
                            @php($memberRole = $member->pivot->role)
                            @php($isSelf = $member->is(auth()->user()))
                            @php($editable = $canManage && ! $isSelf && ($actorRole === OrganizationRole::Owner || $memberRole !== OrganizationRole::Owner))
                            <tr wire:key="member-{{ $member->id }}">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <x-ui.avatar :src="$member->avatarUrl()" :initials="$member->initials()" />
                                        <div class="grid min-w-0 leading-tight">
                                            <span class="truncate font-medium">
                                                {{ $member->name }}
                                                @if ($isSelf)
                                                    <span class="ml-1 rounded bg-muted px-1.5 py-0.5 text-xs font-normal text-muted-foreground">You</span>
                                                @endif
                                            </span>
                                            <span class="truncate text-xs text-muted-foreground">{{ $member->email }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    @if ($editable)
                                        <x-ui.select class="h-8 w-32" aria-label="Role of {{ $member->name }}" wire:change="changeRole({{ $member->id }}, $event.target.value)">
                                            @foreach (OrganizationRole::cases() as $option)
                                                @if ($actorRole->isAtLeast($option))
                                                    <option value="{{ $option->value }}" @selected($option === $memberRole)>{{ $option->label() }}</option>
                                                @endif
                                            @endforeach
                                        </x-ui.select>
                                    @else
                                        <x-ui.badge :variant="$memberRole === OrganizationRole::Owner ? 'default' : 'secondary'">{{ $memberRole->label() }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-muted-foreground" title="{{ $member->pivot->created_at?->toDayDateTimeString() }}">{{ $member->pivot->created_at?->diffForHumans() ?? '—' }}</td>
                                <td class="text-right">
                                    @if ($editable)
                                        <x-ui.button variant="ghost" size="icon" class="size-8" wire:click="removeMember({{ $member->id }})" wire:confirm="Remove {{ $member->name }} from the organization?" aria-label="Remove {{ $member->name }}" title="Remove">
                                            <x-ui.icon name="trash" />
                                        </x-ui.button>
                                    @elseif ($isSelf && $total > 1)
                                        <x-ui.button variant="ghost" size="sm" wire:click="removeMember({{ $member->id }})" wire:confirm="Leave {{ $this->organization->name }}?"><x-ui.icon name="log-out" /> Leave</x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($this->members->hasPages())
                <div class="table-foot">{{ $this->members->links() }}</div>
            @endif
        @endif
    </div>

    @if ($canManage && $this->invitations->isNotEmpty())
        <section class="grid gap-3">
            <h2 class="text-lg">Pending invitations <span class="text-sm text-muted-foreground tabular-nums">{{ $this->invitations->count() }}</span></h2>
            <div class="table-wrap">
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Expires</th>
                                <th><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->invitations as $invitation)
                                <tr wire:key="invitation-{{ $invitation->id }}">
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <span class="flex size-8 items-center justify-center rounded-full border border-dashed text-muted-foreground"><x-ui.icon name="mail" /></span>
                                            {{ $invitation->email }}
                                        </div>
                                    </td>
                                    <td><x-ui.badge variant="outline">{{ $invitation->role->label() }}</x-ui.badge></td>
                                    <td class="whitespace-nowrap text-muted-foreground">{{ $invitation->expires_at->diffForHumans() }}</td>
                                    <td class="text-right">
                                        <x-ui.button variant="ghost" size="sm" wire:click="revokeInvitation({{ $invitation->id }})" wire:confirm="Revoke the invitation sent to {{ $invitation->email }}?"><x-ui.icon name="x" /> Revoke</x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endif

    @if ($canManage)
        <x-ui.dialog name="invite-member" title="Invite a member" description="They'll get an email with a link to join.">
            <form wire:submit="invite" class="grid gap-4">
                <x-ui.field label="Email" for="inviteEmail" error="inviteEmail">
                    <x-ui.input id="inviteEmail" type="email" wire:model="inviteEmail" placeholder="name@company.com" required />
                </x-ui.field>

                <x-ui.field label="Role" for="inviteRole" error="inviteRole">
                    <div class="grid gap-2">
                        @foreach (OrganizationRole::assignable() as $option)
                            @if ($actorRole->isAtLeast($option))
                                <label class="flex cursor-pointer items-start gap-3 rounded-md border p-3 text-sm has-checked:border-foreground has-checked:bg-accent/50">
                                    <input type="radio" wire:model="inviteRole" value="{{ $option->value }}" class="mt-0.5 accent-foreground" />
                                    <span class="grid gap-0.5">
                                        <span class="font-medium">{{ $option->label() }}</span>
                                        <span class="text-muted-foreground">{{ $option->description() }}</span>
                                    </span>
                                </label>
                            @endif
                        @endforeach
                    </div>
                </x-ui.field>

                <div class="flex justify-end gap-2">
                    <x-ui.button variant="outline" x-on:click="$dispatch('close-dialog')">Cancel</x-ui.button>
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="invite">
                        <x-ui.icon name="loader-circle" class="hidden animate-spin" wire:loading.class.remove="hidden" wire:target="invite" />
                        <x-ui.icon name="mail" wire:loading.class="hidden" wire:target="invite" /> Send invitation
                    </x-ui.button>
                </div>
            </form>
        </x-ui.dialog>
    @endif
</div>
