<?php

use App\Actions\ProposeDonkeySkill;
use App\Enums\FindingStatus;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\ReviewFinding;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    public int $repositoryId;

    #[Url]
    public string $tab = 'overview';

    public function mount(int $repository): void
    {
        $this->repositoryId = $repository;

        if (!in_array($this->tab, ['overview', 'documentation', 'graph', 'security'], true)) {
            $this->tab = 'overview';
        }
    }

    public function rendering($view): void
    {
        $view->title($this->repository->full_name);
    }

    #[Computed]
    public function repository(): Repository
    {
        return auth()->user()->currentOrganization->repositories()
            ->with(['installation', 'latestGraph', 'document'])
            ->findOrFail($this->repositoryId);
    }

    public function proposeSkill(ProposeDonkeySkill $proposeSkill): void
    {
        Gate::authorize('update', $this->repository);

        try {
            $url = $proposeSkill->handle($this->repository);
        } catch (RequestException $exception) {
            report($exception);
            $this->dispatch('toast', message: 'GitHub refused the pull request. Check that the Donkey app may write contents and pull requests.', variant: 'destructive');

            return;
        }

        $this->redirect($url);
    }

    /**
     * @return Collection<int, PullRequest>
     */
    #[Computed]
    public function recentPullRequests(): Collection
    {
        return $this->repository->pullRequests()->latest('id')->limit(8)->get();
    }

    /**
     * @return array{pull_requests: int, reviews: int, findings: int}
     */
    #[Computed]
    public function stats(): array
    {
        return [
            'pull_requests' => $this->repository->pullRequests()->count(),
            'reviews' => $this->repository->pullRequests()->join('review_runs', 'review_runs.pull_request_id', '=', 'pull_requests.id')->count(),
            'findings' => ReviewFinding::query()->where('status', FindingStatus::Published)->whereIn('pull_request_id', $this->repository->pullRequests()->select('id'))->count(),
        ];
    }
}; ?>

@php
    $repository = $this->repository;
    $tabs = ['overview' => 'Overview', 'documentation' => 'Documentation', 'graph' => 'Dependency graph', 'security' => 'Security'];
@endphp

<div class="grid gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="grid gap-1">
            <a href="{{ route('repositories.index') }}" wire:navigate class="text-sm text-muted-foreground hover:text-foreground">Repositories</a>
            <h1 class="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                <x-ui.icon :name="$repository->is_private ? 'lock' : 'globe'" class="text-muted-foreground" />
                {{ $repository->full_name }}
            </h1>
            <p class="text-sm text-muted-foreground">Default branch <code>{{ $repository->default_branch }}</code></p>
        </div>
        <div class="flex gap-2">
            @can('update', $repository)
                <x-ui.button variant="outline" wire:click="proposeSkill" wire:loading.attr="disabled" title="Opens a pull request adding .claude/skills/donkey/SKILL.md">Add Claude skill</x-ui.button>
            @endcan
            <x-ui.button variant="outline" :href="rtrim(config('services.github.web_url'), '/').'/'.$repository->full_name" target="_blank" rel="noopener">View on GitHub</x-ui.button>
        </div>
    </div>

    <div class="flex gap-1 border-b" role="tablist">
        @foreach ($tabs as $key => $label)
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                wire:click="$set('tab', '{{ $key }}')"
                @class(['-mb-px border-b-2 px-4 py-2 text-sm font-medium', 'border-primary text-foreground' => $tab === $key, 'border-transparent text-muted-foreground hover:text-foreground' => $tab !== $key])
            >{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'documentation')
        <livewire:pages::repositories.documentation :repository="$repository->id" :embedded="true" wire:key="documentation" />
    @elseif ($tab === 'security')
        <livewire:pages::security.index :repository="$repository->id" :embedded="true" wire:key="security" />
    @elseif ($tab === 'graph')
        <livewire:pages::repositories.graph :repository="$repository->id" :embedded="true" wire:key="graph" />
    @else
        <div class="grid gap-4 sm:grid-cols-3">
            @foreach ([['Pull requests', $this->stats['pull_requests']], ['Reviews', $this->stats['reviews']], ['Published findings', $this->stats['findings']]] as [$label, $value])
                <div class="grid content-start gap-1 rounded-xl border bg-card p-5 shadow-xs">
                    <span class="text-sm text-muted-foreground">{{ $label }}</span>
                    <span class="text-3xl font-semibold tabular-nums">{{ number_format($value) }}</span>
                </div>
            @endforeach
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-ui.card title="Documentation">
                <div class="grid gap-3 px-6 text-sm">
                    @if ($repository->document?->generated_at)
                        <p class="text-muted-foreground">Generated {{ $repository->document->generated_at->diffForHumans() }} from <code>{{ Str::substr((string) $repository->document->commit_sha, 0, 7) }}</code>.</p>
                        <x-ui.button variant="outline" class="w-fit" wire:click="$set('tab', 'documentation')">Read the documentation</x-ui.button>
                    @else
                        <p class="text-muted-foreground">Not generated yet. Turn on automatic documentation from the repositories list.</p>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card title="Dependency graph">
                <div class="grid gap-3 px-6 text-sm">
                    @if ($repository->latestGraph)
                        <p class="text-muted-foreground">Built {{ $repository->latestGraph->built_at?->diffForHumans() }} from <code>{{ Str::substr($repository->latestGraph->commit_sha, 0, 7) }}</code>.</p>
                        <x-ui.button variant="outline" class="w-fit" wire:click="$set('tab', 'graph')">Explore the graph</x-ui.button>
                    @else
                        <p class="text-muted-foreground">No graph yet. It is built when a pull request is reviewed or merged.</p>
                    @endif
                </div>
            </x-ui.card>
        </div>

        <x-ui.card title="Recent pull requests">
            <div class="px-6">
                @forelse ($this->recentPullRequests as $pullRequest)
                    <a href="{{ route('pull-requests.show', $pullRequest) }}" wire:navigate wire:key="pr-{{ $pullRequest->id }}" class="flex items-center justify-between gap-4 border-b py-2 text-sm last:border-0 hover:text-foreground">
                        <span class="truncate"><span class="text-muted-foreground">#{{ $pullRequest->number }}</span> {{ $pullRequest->title }}</span>
                        <x-ui.badge variant="outline">{{ $pullRequest->state }}</x-ui.badge>
                    </a>
                @empty
                    <p class="text-sm text-muted-foreground">No pull requests yet.</p>
                @endforelse
            </div>
        </x-ui.card>
    @endif
</div>
