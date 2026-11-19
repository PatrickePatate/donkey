<?php

use App\Models\AuditEvent;
use App\Models\Organization;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Audit log')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $category = '';

    /**
     * Filter tabs: label => action prefix.
     */
    public const CATEGORIES = [
        'members' => 'Members',
        'organization' => 'Settings',
        'repository' => 'Repositories',
        'agent' => 'Agents',
        'learning' => 'Knowledge',
    ];

    /**
     * Icon and sentence for each action; the sentence follows the actor's name.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ACTIONS = [
        'member.invited' => ['user-plus', 'invited a member'],
        'member.invitation_revoked' => ['x', 'revoked an invitation'],
        'member.role_changed' => ['users', 'changed a member\'s role'],
        'member.removed' => ['trash', 'removed a member'],
        'member.left' => ['log-out', 'left the organization'],
        'member.auto_joined' => ['user-plus', 'joined automatically from a verified email domain'],
        'organization.updated' => ['settings', 'updated organization settings'],
        'organization.ai_changed' => ['sparkles', 'changed the AI provider'],
        'organization.budget_changed' => ['activity', 'changed the monthly budget'],
        'organization.prices_changed' => ['activity', 'changed model prices'],
        'github.connected' => ['git-branch', 'connected GitHub'],
        'repository.auto_review_changed' => ['git-pull-request', 'changed auto-review on a repository'],
        'repository.auto_docs_changed' => ['file-code', 'changed automatic docs on a repository'],
        'repository.risk_threshold_changed' => ['shield', 'changed a repository\'s risk threshold'],
        'repository.security_branch_changed' => ['shield', 'changed a repository\'s security branch'],
        'agent.created' => ['bot', 'created an agent'],
        'agent.updated' => ['bot', 'updated an agent'],
        'agent.deleted' => ['trash', 'deleted an agent'],
        'learning.approved' => ['circle-check', 'approved a learning'],
        'learning.rejected' => ['x', 'rejected a learning'],
        'learning.updated' => ['lightbulb', 'edited a learning'],
        'memory.deleted' => ['trash', 'deleted a repository memory'],
    ];

    /**
     * @return array{icon: string, sentence: string}
     */
    public function presentation(AuditEvent $event): array
    {
        [$icon, $sentence] = self::ACTIONS[$event->action] ?? ['circle-help', str($event->action)->replace(['.', '_'], ' ')->lower()->toString()];

        return ['icon' => $icon, 'sentence' => $sentence];
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function mount(): void
    {
        Gate::authorize('viewAudit', $this->organization);
    }

    #[Computed]
    public function organization(): Organization
    {
        return auth()->user()->currentOrganization;
    }

    /**
     * @return Paginator<int, AuditEvent>
     */
    #[Computed]
    public function events(): Paginator
    {
        return $this->organization->auditEvents()
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('action', "%{$this->search}%")
                ->orWhereLike('actor', "%{$this->search}%")))
            ->when(array_key_exists($this->category, self::CATEGORIES), fn ($query) => $query->where(fn ($query) => $this->category === 'members'
                ? $query->whereLike('action', 'member.%')
                : $query->whereLike('action', "{$this->category}.%")->when($this->category === 'organization', fn ($query) => $query->orWhereLike('action', 'github.%'))
            ))
            ->latest('id')
            ->simplePaginate(50);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function describe(?array $metadata): string
    {
        return collect($this->details($metadata))
            ->map(fn (array $detail): string => $detail['key'].': '.($detail['from'] !== null ? $detail['from'].' → ' : '').$detail['to'])
            ->implode(' · ');
    }

    /**
     * Metadata as rows; [old, new] pairs become a change.
     *
     * @param  array<string, mixed>|null  $metadata
     * @return list<array{key: string, from: ?string, to: string}>
     */
    public function details(?array $metadata): array
    {
        return collect($metadata ?? [])
            ->map(fn ($value, string $key): array => is_array($value) && array_is_list($value) && count($value) === 2
                ? ['key' => str_replace('_', ' ', $key), 'from' => $this->value($value[0]), 'to' => $this->value($value[1])]
                : ['key' => str_replace('_', ' ', $key), 'from' => null, 'to' => $this->value($value)])
            ->values()
            ->all();
    }

    private function value(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => implode(', ', array_map(fn ($item) => is_scalar($item) ? (string) $item : json_encode($item), $value)),
            default => str((string) $value)->limit(80)->toString(),
        };
    }
};
?>

<div class="grid gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="grid gap-1">
            <h1 class="text-2xl tracking-tight">Audit log</h1>
            <p class="text-sm text-muted-foreground">Changes to settings, members, agents, learnings and budgets. Kept {{ config('retention.audit_days') }} days.</p>
        </div>
    </div>

    <div class="table-wrap">
        <div class="table-toolbar">
            <div class="flex flex-wrap gap-1 text-sm">
                @foreach (['' => 'All'] + $this::CATEGORIES as $value => $label)
                    <button type="button" wire:click="$set('category', '{{ $value }}')" @class(['rounded-md px-2.5 py-1 transition-colors', 'bg-accent font-medium text-foreground' => $category === $value, 'text-muted-foreground hover:text-foreground' => $category !== $value])>{{ $label }}</button>
                @endforeach
            </div>
            <label class="table-search">
                <x-ui.icon name="search" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Filter by action or person…" aria-label="Filter events">
            </label>
        </div>

        @if ($this->events->isEmpty())
            <div class="grid justify-items-center gap-2 py-14 text-center">
                <span class="flex size-10 items-center justify-center rounded-full bg-muted text-muted-foreground"><x-ui.icon name="shield" /></span>
                <p class="text-sm text-muted-foreground">No events{{ $search !== '' ? ' match "'.$search.'"' : ($category !== '' ? ' in this category' : ' yet') }}.</p>
            </div>
        @else
            @foreach ($this->events->groupBy(fn ($event) => $event->created_at->toDateString()) as $date => $events)
                @php($day = Carbon::parse($date))
                <div wire:key="day-{{ $date }}">
                    <p class="sticky top-0 z-[1] border-b bg-muted/50 px-4 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase backdrop-blur">
                        {{ $day->isToday() ? 'Today' : ($day->isYesterday() ? 'Yesterday' : $day->format('l, F j'.($day->isCurrentYear() ? '' : ', Y'))) }}
                    </p>
                    <ul class="divide-y">
                        @foreach ($events as $event)
                            @php($presentation = $this->presentation($event))
                            @php($details = $this->details($event->metadata))
                            <li wire:key="audit-{{ $event->id }}" x-data="{ open: false }" class="px-4 py-3">
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full border bg-background text-muted-foreground"><x-ui.icon :name="$presentation['icon']" /></span>
                                    <div class="grid min-w-0 flex-1 gap-1">
                                        <p class="text-sm">
                                            <span class="font-medium">{{ $event->actor }}</span>
                                            <span class="text-muted-foreground">{{ $presentation['sentence'] }}</span>
                                        </p>
                                        @if ($details !== [])
                                            <div class="flex flex-wrap gap-1.5" x-show="! open">
                                                @foreach (array_slice($details, 0, 3) as $detail)
                                                    <span class="inline-flex max-w-full items-center gap-1 truncate rounded-md bg-muted px-1.5 py-0.5 text-xs">
                                                        <span class="text-muted-foreground">{{ $detail['key'] }}</span>
                                                        @if ($detail['from'] !== null)
                                                            <span class="text-muted-foreground line-through">{{ Str::limit($detail['from'], 24) }}</span>
                                                            <x-ui.icon name="arrow-right" class="size-3 text-muted-foreground" />
                                                        @endif
                                                        <span class="truncate font-medium">{{ Str::limit($detail['to'], 40) }}</span>
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                    <div class="flex shrink-0 items-center gap-1">
                                        <span class="text-xs text-muted-foreground tabular-nums" title="{{ $event->created_at->toDayDateTimeString() }}">{{ $event->created_at->format('H:i') }}</span>
                                        <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" class="rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-foreground" aria-label="Show details">
                                            <x-ui.icon name="chevron-down" class="transition-transform" x-bind:class="open && 'rotate-180'" />
                                        </button>
                                    </div>
                                </div>

                                <dl x-cloak x-show="open" class="mt-3 ml-11 grid gap-x-6 gap-y-1.5 rounded-md border bg-muted/30 p-3 text-xs sm:grid-cols-[8rem_1fr]">
                                    @foreach ($details as $detail)
                                        <dt class="text-muted-foreground capitalize">{{ $detail['key'] }}</dt>
                                        <dd class="break-words">
                                            @if ($detail['from'] !== null)
                                                <span class="text-muted-foreground line-through">{{ $detail['from'] }}</span> → 
                                            @endif
                                            <span class="font-medium">{{ $detail['to'] }}</span>
                                        </dd>
                                    @endforeach
                                    <dt class="text-muted-foreground">Action</dt>
                                    <dd><code class="font-mono">{{ $event->action }}</code></dd>
                                    <dt class="text-muted-foreground">When</dt>
                                    <dd>{{ $event->created_at->toDayDateTimeString() }}</dd>
                                    @if ($event->ip_address)
                                        <dt class="text-muted-foreground">IP address</dt>
                                        <dd class="font-mono">{{ $event->ip_address }}</dd>
                                    @endif
                                </dl>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
            @if ($this->events->hasPages())
                <div class="table-foot">{{ $this->events->links() }}</div>
            @endif
        @endif
    </div>
</div>
