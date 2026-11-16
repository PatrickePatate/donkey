<?php

use App\Jobs\SyncInstallationRepositories;
use App\Models\GithubInstallation;
use App\Models\Organization;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Repositories')] class extends Component
{
    #[Url(except: '')]
    public string $search = '';

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            "echo-private:organizations.{$this->organization->id},.repositories.synced" => '$refresh',
        ];
    }

    #[Computed]
    public function organization(): Organization
    {
        return auth()->user()->currentOrganization;
    }

    /**
     * @return Collection<int, Repository>
     */
    #[Computed]
    public function repositories(): Collection
    {
        return $this->organization->repositories()
            ->with(['installation', 'organization'])
            ->when($this->search !== '', fn ($query) => $query->whereLike('full_name', "%{$this->search}%"))
            ->orderBy('full_name')
            ->get();
    }

    /**
     * @return Collection<int, GithubInstallation>
     */
    #[Computed]
    public function installations(): Collection
    {
        return $this->organization->githubInstallations()->withCount('repositories')->orderBy('account_login')->get();
    }

    public function setAutoReview(int $repositoryId, ?bool $enabled): void
    {
        $repository = $this->findRepository($repositoryId);
        Gate::authorize('update', $repository);

        $repository->update(['auto_review_enabled' => $enabled]);

        $this->dispatch('toast', message: "Auto-review updated for {$repository->full_name}.", variant: 'success');
    }

    public function setRiskThreshold(int $repositoryId, mixed $value): void
    {
        $repository = $this->findRepository($repositoryId);
        Gate::authorize('update', $repository);

        $threshold = $value === '' || $value === null ? null : (int) $value;

        if ($threshold !== null && ($threshold < 0 || $threshold > 100)) {
            $this->dispatch('toast', message: 'The risk threshold must be between 0 and 100.', variant: 'destructive');

            return;
        }

        $repository->update(['risk_threshold' => $threshold]);

        $this->dispatch('toast', message: "Risk threshold updated for {$repository->full_name}.", variant: 'success');
    }

    public function resync(int $installationId): void
    {
        Gate::authorize('manageGithub', $this->organization);

        $installation = $this->organization->githubInstallations()->findOrFail($installationId);
        SyncInstallationRepositories::dispatch($installation);

        $this->dispatch('toast', message: "Syncing repositories for {$installation->account_login}…");
    }

    private function findRepository(int $repositoryId): Repository
    {
        return $this->organization->repositories()->with('organization')->findOrFail($repositoryId);
    }
};
?>

<div class="grid gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="grid gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">Repositories</h1>
            <p class="text-sm text-muted-foreground">Choose which repositories get reviewed and how strict the risk gate is.</p>
        </div>

        @can('manageGithub', $this->organization)
            <x-ui.button :href="route('github.install')">
                <x-ui.icon name="github" /> Connect GitHub
            </x-ui.button>
        @endcan
    </div>

    @if ($this->installations->isNotEmpty())
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->installations as $installation)
                <div wire:key="installation-{{ $installation->id }}" class="flex items-center gap-3 rounded-lg border bg-card p-4">
                    <x-ui.icon name="github" class="size-5" />
                    <div class="grid flex-1 text-sm">
                        <span class="font-medium">{{ $installation->account_login }}</span>
                        <span class="text-xs text-muted-foreground">
                            {{ $installation->repositories_count }} {{ str('repository')->plural($installation->repositories_count) }}
                            · {{ $installation->synced_at ? 'synced '.$installation->synced_at->diffForHumans() : 'syncing…' }}
                        </span>
                    </div>
                    @if ($installation->isSuspended())
                        <x-ui.badge variant="destructive">Suspended</x-ui.badge>
                    @endif
                    @can('manageGithub', $this->organization)
                        <x-ui.button variant="ghost" size="icon" wire:click="resync({{ $installation->id }})" title="Resync">
                            <x-ui.icon name="refresh" />
                        </x-ui.button>
                    @endcan
                </div>
            @endforeach
        </div>
    @endif

    <x-ui.card>
        <div class="grid gap-4">
            <div class="relative max-w-sm">
                <x-ui.icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground" />
                <x-ui.input wire:model.live.debounce.300ms="search" placeholder="Search repositories…" class="pl-9" />
            </div>

            @if ($this->repositories->isEmpty())
                <div class="flex flex-col items-center gap-2 py-12 text-center">
                    <x-ui.icon name="git-branch" class="size-8 text-muted-foreground" />
                    <p class="font-medium">No repositories yet</p>
                    <p class="max-w-sm text-sm text-muted-foreground">
                        @if ($this->search !== '')
                            Nothing matches "{{ $this->search }}".
                        @else
                            Connect the GitHub App and pick the repositories you want reviewed. They'll appear here automatically.
                        @endif
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-left text-muted-foreground">
                                <th class="h-10 px-2 font-medium">Repository</th>
                                <th class="h-10 px-2 font-medium">Auto-review</th>
                                <th class="h-10 px-2 font-medium">Risk threshold</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->repositories as $repository)
                                @php($canUpdate = auth()->user()->can('update', $repository))
                                <tr wire:key="repository-{{ $repository->id }}" class="border-b last:border-0 hover:bg-muted/50">
                                    <td class="p-2">
                                        <div class="flex items-center gap-2">
                                            <x-ui.icon :name="$repository->is_private ? 'lock' : 'globe'" class="text-muted-foreground" />
                                            <span class="font-medium">{{ $repository->full_name }}</span>
                                        </div>
                                    </td>
                                    <td class="p-2">
                                        <div class="flex items-center gap-2">
                                            <x-ui.switch
                                                wire:key="switch-{{ $repository->id }}-{{ $repository->autoReviewEnabled() ? 1 : 0 }}"
                                                :checked="$repository->autoReviewEnabled()"
                                                x-on:switch-change="$wire.setAutoReview({{ $repository->id }}, $event.detail)"
                                                :disabled="! $canUpdate"
                                                aria-label="Auto-review"
                                            />
                                            @if ($repository->overridesAutoReview())
                                                @if ($canUpdate)
                                                    <button type="button" class="text-xs text-muted-foreground underline-offset-4 hover:underline" wire:click="setAutoReview({{ $repository->id }}, null)">Reset</button>
                                                @endif
                                            @else
                                                <x-ui.badge variant="secondary">Org default</x-ui.badge>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="p-2">
                                        <div class="flex items-center gap-2">
                                            <x-ui.input
                                                type="number"
                                                min="0"
                                                max="100"
                                                class="h-8 w-20"
                                                value="{{ $repository->risk_threshold }}"
                                                placeholder="{{ $this->organization->risk_threshold }}"
                                                wire:change="setRiskThreshold({{ $repository->id }}, $event.target.value)"
                                                :disabled="! $canUpdate"
                                            />
                                            @unless ($repository->overridesRiskThreshold())
                                                <x-ui.badge variant="secondary">Org default</x-ui.badge>
                                            @endunless
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </x-ui.card>
</div>
