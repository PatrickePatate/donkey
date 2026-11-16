<?php

use App\Models\AuditEvent;
use App\Models\Organization;
use Illuminate\Contracts\Pagination\Paginator;
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
        return collect($metadata ?? [])
            ->map(fn ($value, string $key): string => $key.': '.(is_array($value) && array_is_list($value) && count($value) === 2
                ? $this->value($value[0]).' → '.$this->value($value[1])
                : $this->value($value)))
            ->implode(' · ');
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
            <h1 class="text-2xl font-semibold tracking-tight">Audit log</h1>
            <p class="text-sm text-muted-foreground">Changes to settings, members, agents, learnings and budgets. Kept {{ config('retention.audit_days') }} days.</p>
        </div>
        <div class="relative w-64">
            <x-ui.icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground" />
            <x-ui.input wire:model.live.debounce.300ms="search" placeholder="Filter by action or person…" class="pl-9" />
        </div>
    </div>

    <x-ui.card>
        @if ($this->events->isEmpty())
            <p class="py-10 text-center text-sm text-muted-foreground">No events{{ $search !== '' ? ' match "'.$search.'"' : ' yet' }}.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-muted-foreground">
                            <th class="h-10 px-2 font-medium">When</th>
                            <th class="h-10 px-2 font-medium">Who</th>
                            <th class="h-10 px-2 font-medium">Action</th>
                            <th class="h-10 px-2 font-medium">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->events as $event)
                            <tr wire:key="audit-{{ $event->id }}" class="border-b align-top last:border-0">
                                <td class="p-2 whitespace-nowrap text-muted-foreground" title="{{ $event->created_at->toDayDateTimeString() }}">{{ $event->created_at->diffForHumans() }}</td>
                                <td class="p-2 whitespace-nowrap">{{ $event->actor }}</td>
                                <td class="p-2"><code class="rounded bg-muted px-1.5 py-0.5 text-xs">{{ $event->action }}</code></td>
                                <td class="max-w-md p-2 text-xs break-words text-muted-foreground">{{ $this->describe($event->metadata) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $this->events->links() }}</div>
        @endif
    </x-ui.card>
</div>
