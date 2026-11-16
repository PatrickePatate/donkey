<?php

use App\Models\Organization;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $channel = "echo-private:organizations.{$this->organization->id}";

        return [
            "{$channel},.repositories.synced" => '$refresh',
            "{$channel},.webhook.processed" => '$refresh',
        ];
    }

    #[Computed]
    public function organization(): Organization
    {
        return auth()->user()->currentOrganization;
    }

    /**
     * @return array{repositories: int, auto_review: int, installations: int, members: int}
     */
    #[Computed]
    public function stats(): array
    {
        $repositories = $this->organization->repositories()->with('organization')->get();

        return [
            'repositories' => $repositories->count(),
            'auto_review' => $repositories->filter->autoReviewEnabled()->count(),
            'installations' => $this->organization->githubInstallations()->count(),
            'members' => $this->organization->members()->count(),
        ];
    }

    /**
     * @return Collection<int, WebhookEvent>
     */
    #[Computed]
    public function recentEvents(): Collection
    {
        return WebhookEvent::query()
            ->whereIn('installation_id', $this->organization->githubInstallations()->select('installation_id'))
            ->latest()
            ->limit(10)
            ->get(['id', 'event', 'action', 'status', 'created_at']);
    }
};
?>

<div class="grid gap-6">
    <div class="grid gap-1">
        <h1 class="text-2xl font-semibold tracking-tight">Dashboard</h1>
        <p class="text-sm text-muted-foreground">Overview of {{ $this->organization->name }}.</p>
    </div>

    @if ($this->stats['installations'] === 0)
        <x-ui.alert title="Connect GitHub to get started">
            <x-slot:icon><x-ui.icon name="github" /></x-slot:icon>
            <p>Install the GitHub App on your account or organization, then pick the repositories to review.</p>
            @can('manageGithub', $this->organization)
                <div class="mt-2">
                    <x-ui.button size="sm" :href="route('github.install')"><x-ui.icon name="github" /> Connect GitHub</x-ui.button>
                </div>
            @endcan
        </x-ui.alert>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Repositories', $this->stats['repositories'], 'git-branch'],
            ['Auto-review on', $this->stats['auto_review'], 'shield'],
            ['GitHub connections', $this->stats['installations'], 'github'],
            ['Members', $this->stats['members'], 'users'],
        ] as [$label, $value, $icon])
            <div class="grid gap-2 rounded-xl border bg-card p-5 shadow-xs">
                <div class="flex items-center justify-between text-sm text-muted-foreground">
                    {{ $label }}
                    <x-ui.icon :name="$icon" />
                </div>
                <p class="text-3xl font-semibold tabular-nums">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <x-ui.card title="Live activity" description="GitHub events for this organization, updated in real time.">
        @if ($this->recentEvents->isEmpty())
            <p class="py-6 text-center text-sm text-muted-foreground">No events yet.</p>
        @else
            <ul class="divide-y">
                @foreach ($this->recentEvents as $event)
                    <li wire:key="event-{{ $event->id }}" class="flex items-center gap-3 py-2.5 text-sm">
                        <x-ui.icon name="activity" class="text-muted-foreground" />
                        <span class="font-mono text-xs">{{ $event->event }}{{ $event->action ? '.'.$event->action : '' }}</span>
                        <x-ui.badge :variant="match ($event->status->value) { 'processed' => 'success', 'failed' => 'destructive', default => 'secondary' }">
                            {{ $event->status->value }}
                        </x-ui.badge>
                        <span class="ml-auto text-xs text-muted-foreground">{{ $event->created_at->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
