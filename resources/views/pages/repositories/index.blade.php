<?php

use App\Jobs\BuildRepositoryGraph;
use App\Jobs\GenerateRepositoryDocumentation;
use App\Jobs\SyncInstallationRepositories;
use App\Models\GithubInstallation;
use App\Models\Organization;
use App\Models\Repository;
use App\Services\Audit\AuditLog;
use App\Services\GitHub\PullRequestClient;
use App\Services\Graph\KodusGraph;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Repositories')] class extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

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
     * @return LengthAwarePaginator<int, Repository>
     */
    #[Computed]
    public function repositories(): LengthAwarePaginator
    {
        return $this->organization->repositories()
            ->with(['installation', 'organization', 'latestGraph', 'document'])
            ->when($this->search !== '', fn ($query) => $query->whereLike('full_name', "%{$this->search}%"))
            ->orderBy('full_name')
            ->paginate(25);
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
        app(\App\Services\Audit\AuditLog::class)->record($this->organization, 'repository.auto_review_changed', $repository, ['auto_review_enabled' => $enabled]);

        $this->dispatch('toast', message: "Auto-review updated for {$repository->full_name}.", variant: 'success');
    }

    public function setAutoDocs(int $repositoryId, bool $enabled): void
    {
        $repository = $this->findRepository($repositoryId);
        Gate::authorize('update', $repository);

        if ($repository->auto_docs_enabled === $enabled) {
            return;
        }

        $repository->update(['auto_docs_enabled' => $enabled]);
        app(AuditLog::class)->record($this->organization, 'repository.auto_docs_changed', $repository, AuditLog::changes($repository, ['auto_docs_enabled']));

        if ($enabled && !$repository->document()->whereNotNull('content')->exists()) {
            GenerateRepositoryDocumentation::dispatchFor($repository, immediate: true);
        }

        $this->dispatch('toast', message: "Automatic documentation updated for {$repository->full_name}.", variant: 'success');
    }

    public function regenerateDocumentation(int $repositoryId): void
    {
        $repository = $this->findRepository($repositoryId);
        Gate::authorize('update', $repository);

        if (!$repository->auto_docs_enabled) {
            $this->dispatch('toast', message: 'Enable automatic documentation before regenerating it.', variant: 'destructive');

            return;
        }

        GenerateRepositoryDocumentation::dispatchFor($repository, immediate: true);

        $this->dispatch('toast', message: "Regenerating documentation for {$repository->full_name}…");
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
        app(\App\Services\Audit\AuditLog::class)->record($this->organization, 'repository.risk_threshold_changed', $repository, ['risk_threshold' => $threshold]);

        $this->dispatch('toast', message: "Risk threshold updated for {$repository->full_name}.", variant: 'success');
    }

    public function resync(int $installationId): void
    {
        Gate::authorize('manageGithub', $this->organization);

        $installation = $this->organization->githubInstallations()->findOrFail($installationId);
        SyncInstallationRepositories::dispatch($installation);

        $this->dispatch('toast', message: "Syncing repositories for {$installation->account_login}…");
    }

    #[Computed]
    public function graphAvailable(): bool
    {
        return app(KodusGraph::class)->isAvailable();
    }

    public function rebuildGraph(int $repositoryId, PullRequestClient $client): void
    {
        $repository = $this->findRepository($repositoryId);
        Gate::authorize('update', $repository);

        try {
            $sha = $client->branchHead($repository, $repository->default_branch);
        } catch (\Throwable $exception) {
            report($exception);
            $this->dispatch('toast', message: "Couldn't reach GitHub to find the latest commit of {$repository->default_branch}.", variant: 'destructive');

            return;
        }

        BuildRepositoryGraph::dispatch($repository, $sha);

        $this->dispatch('toast', message: "Building the code graph of {$repository->full_name}…");
    }

    public function buildAllGraphs(): void
    {
        Gate::authorize('update', $this->organization);

        // The job resolves each default branch head itself, and is unique per repository while queued.
        $this->organization->repositories()->each(fn (Repository $repository) => BuildRepositoryGraph::dispatch($repository));

        $this->dispatch('toast', message: 'Building the code graph of every repository…');
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

        <div class="flex flex-wrap gap-2">
            @if ($this->graphAvailable && $this->repositories->isNotEmpty())
                @can('update', $this->organization)
                    <x-ui.button variant="outline" wire:click="buildAllGraphs">
                        <x-ui.icon name="activity" /> Build all graphs
                    </x-ui.button>
                @endcan
            @endif

        @can('manageGithub', $this->organization)
            <x-ui.button :href="route('github.install')">
                <x-ui.icon name="github" /> Connect GitHub
            </x-ui.button>
        @endcan
        </div>
    </div>

    @if (config('graph.enabled') && ! $this->graphAvailable && $this->repositories->isNotEmpty())
        <x-ui.alert title="Code graph unavailable">
            <x-slot:icon><x-ui.icon name="activity" /></x-slot:icon>
            <p><code>kodus-graph</code> isn't installed on this server, so reviews run on the diff alone, without callers, contract changes and test coverage. Install it with <code>npm install</code> then <code>npm run graph:setup</code>.</p>
        </x-ui.alert>
    @endif

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
                <div class="overflow-x-auto md:overflow-visible">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-left text-muted-foreground">
                                <th class="h-10 px-2 font-medium">Repository</th>
                                <th class="h-10 px-2 font-medium">Auto-review</th>
                                <th class="h-10 px-2 font-medium">Risk threshold</th>
                                <th class="h-10 px-2 font-medium">Code graph</th>
                                <th class="h-10 px-2 font-medium">Docs</th>
                                <th class="h-10 w-10 px-2"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->repositories as $repository)
                                @php($canUpdate = auth()->user()->can('update', $repository))
                                <tr wire:key="repository-{{ $repository->id }}" class="border-b last:border-0 transition-colors hover:bg-muted/50">
                                    <td class="p-2">
                                        <div class="flex items-center gap-2">
                                            <x-ui.icon :name="$repository->is_private ? 'lock' : 'globe'" class="text-muted-foreground" />
                                            <a href="{{ route('repositories.show', $repository) }}" wire:navigate class="font-medium hover:underline">{{ $repository->full_name }}</a>
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
                                    <td class="p-2">
                                        @php($graph = $repository->latestGraph)
                                        @if ($graph)
                                            <div class="flex items-center gap-2">
                                                <x-ui.badge :variant="$graph->status->badgeVariant()" :title="$graph->error">{{ $graph->status->label() }}</x-ui.badge>
                                                @if ($graph->built_at)
                                                    <span class="text-xs text-muted-foreground" title="{{ number_format($graph->nodes_count) }} nodes · {{ number_format($graph->edges_count) }} edges · {{ \Illuminate\Support\Str::substr($graph->commit_sha, 0, 7) }}">{{ $graph->built_at->diffForHumans(short: true) }}</span>
                                                @endif
                                            </div>
                                        @else
                                            <span class="text-xs text-muted-foreground">Not built</span>
                                        @endif
                                    </td>
                                    <td class="p-2">
                                        @if ($repository->auto_docs_enabled)
                                            <x-ui.badge :variant="filled($repository->document?->content) ? 'success' : 'secondary'">{{ filled($repository->document?->content) ? 'Ready' : 'Pending' }}</x-ui.badge>
                                        @else
                                            <span class="text-xs text-muted-foreground">Off</span>
                                        @endif
                                    </td>
                                    <td class="p-2 text-right">
                                        <x-ui.dropdown width="w-60" context="tr">
                                            <x-slot:trigger>
                                                <x-ui.button variant="ghost" size="icon" class="size-8" aria-label="Actions for {{ $repository->full_name }}">
                                                    <x-ui.icon name="ellipsis" />
                                                </x-ui.button>
                                            </x-slot:trigger>

                                            <x-ui.dropdown-item :href="route('repositories.show', $repository)" wire:navigate>
                                                <x-ui.icon name="external-link" /> Open
                                            </x-ui.dropdown-item>
                                            @if ($graph?->status === \App\Enums\GraphStatus::Ready)
                                                <x-ui.dropdown-item :href="route('repositories.show', ['repository' => $repository, 'tab' => 'graph'])" wire:navigate>
                                                    <x-ui.icon name="git-branch" /> Explore code graph
                                                </x-ui.dropdown-item>
                                            @endif
                                            @if (filled($repository->document?->content))
                                                <x-ui.dropdown-item :href="route('repositories.show', ['repository' => $repository, 'tab' => 'documentation'])" wire:navigate>
                                                    <x-ui.icon name="file-code" /> View documentation
                                                </x-ui.dropdown-item>
                                            @endif

                                            @if ($canUpdate)
                                                <x-ui.separator class="my-1" />
                                                @if ($this->graphAvailable)
                                                    <x-ui.dropdown-item wire:click="rebuildGraph({{ $repository->id }})" x-on:click="open = false">
                                                        <x-ui.icon name="refresh" /> {{ $graph ? 'Rebuild' : 'Build' }} code graph
                                                    </x-ui.dropdown-item>
                                                @endif
                                                <x-ui.dropdown-item wire:click="setAutoDocs({{ $repository->id }}, {{ $repository->auto_docs_enabled ? 'false' : 'true' }})" x-on:click="open = false">
                                                    <x-ui.icon name="file-code" /> {{ $repository->auto_docs_enabled ? 'Turn off' : 'Turn on' }} automatic docs
                                                </x-ui.dropdown-item>
                                                @if ($repository->auto_docs_enabled)
                                                    <x-ui.dropdown-item wire:click="regenerateDocumentation({{ $repository->id }})" x-on:click="open = false">
                                                        <x-ui.icon name="refresh" /> Regenerate documentation
                                                    </x-ui.dropdown-item>
                                                @endif
                                            @endif
                                        </x-ui.dropdown>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{ $this->repositories->links() }}
            @endif
        </div>
    </x-ui.card>
</div>
