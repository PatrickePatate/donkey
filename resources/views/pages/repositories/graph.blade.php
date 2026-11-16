<?php

use App\Models\Repository;
use App\Models\RepositoryGraph;
use App\Services\Graph\GraphExplorer;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Dependency graph')] class extends Component
{
    public int $repositoryId;

    public bool $embedded = false;

    #[Url(as: 'node')]
    public string $focus = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'type')]
    public string $category = '';

    public function mount(int $repository): void
    {
        $this->repositoryId = $repository;
    }

    #[Computed]
    public function repository(): Repository
    {
        return auth()->user()->currentOrganization->repositories()->findOrFail($this->repositoryId);
    }

    #[Computed]
    public function graph(): ?RepositoryGraph
    {
        return app(GraphExplorer::class)->latestGraph($this->repository);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return $this->graph ? app(GraphExplorer::class)->categoryCounts($this->graph) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function results(): array
    {
        return $this->graph ? app(GraphExplorer::class)->search($this->graph, $this->search, $this->category ?: null) : [];
    }

    /**
     * @return ?array<string, mixed>
     */
    #[Computed]
    public function neighbourhood(): ?array
    {
        return $this->graph && $this->focus !== '' ? app(GraphExplorer::class)->neighbourhood($this->graph, $this->focus) : null;
    }

    public function select(string $id): void
    {
        $this->focus = $id;
    }

    public function filter(string $category): void
    {
        $this->category = $this->category === $category ? '' : $category;
    }
}; ?>

@php
    $colors = ['module' => '#64748b', 'class' => '#6366f1', 'service' => '#0ea5e9', 'model' => '#f59e0b', 'endpoint' => '#10b981', 'method' => '#8b5cf6', 'test' => '#ec4899'];
@endphp

<div class="grid gap-6">
    @unless ($embedded)
        <div class="grid gap-1">
            <a href="{{ route('repositories.show', $this->repository) }}" wire:navigate class="text-sm text-muted-foreground hover:text-foreground">{{ $this->repository->full_name }}</a>
            <h1 class="text-2xl font-semibold tracking-tight">Dependency graph</h1>
        </div>
    @endunless

    <p class="text-sm text-muted-foreground">
        @if ($this->graph)
            Built {{ $this->graph->built_at?->diffForHumans() }} from <code>{{ Str::substr($this->graph->commit_sha, 0, 7) }}</code>.
        @else
            No code graph has been built for this repository yet.
        @endif
    </p>

    @if (! $this->graph)
        <x-ui.alert title="No graph yet">
            <p>A graph is built automatically when a pull request on this repository is reviewed or merged.</p>
        </x-ui.alert>
    @else
        <div class="flex flex-wrap gap-2">
            @foreach (GraphExplorer::CATEGORIES as $key => $label)
                <button type="button" wire:click="filter('{{ $key }}')" @class(['inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs', 'bg-muted font-medium' => $category === $key])>
                    <span class="size-2 rounded-full" style="background: {{ $colors[$key] }}"></span>
                    {{ $label }} <span class="text-muted-foreground">{{ $this->counts[$key] ?? 0 }}</span>
                </button>
            @endforeach
        </div>

        <div class="grid gap-6 lg:grid-cols-[18rem_1fr]">
            <x-ui.card>
                <div class="grid gap-3">
                    <x-ui.input wire:model.live.debounce.300ms="search" placeholder="Search classes, methods, files…" aria-label="Search the graph" />
                    <ul class="grid max-h-[32rem] gap-1 overflow-y-auto text-sm">
                        @forelse ($this->results as $result)
                            <li wire:key="result-{{ md5($result['id']) }}">
                                <button type="button" wire:click="select(@js($result['id']))" @class(['flex w-full items-center gap-2 truncate rounded px-2 py-1 text-left hover:bg-muted', 'bg-muted font-medium' => $focus === $result['id']]) title="{{ $result['file'] }}">
                                    <span class="size-2 shrink-0 rounded-full" style="background: {{ $colors[$result['category']] }}"></span>
                                    <span class="truncate">{{ $result['label'] }}</span>
                                </button>
                            </li>
                        @empty
                            <li class="text-muted-foreground">No matching nodes.</li>
                        @endforelse
                    </ul>
                </div>
            </x-ui.card>

            @if ($hood = $this->neighbourhood)
                @php
                    $left = $hood['callers'];
                    $right = $hood['dependencies'];
                    $rows = max(count($left), count($right), 1);
                    $height = max(160, $rows * 34 + 40);
                    $centerY = $height / 2;
                    $y = fn (int $index, int $count) => $count === 1 ? $centerY : 20 + $index * (($height - 40) / max($count - 1, 1));
                @endphp
                <x-ui.card>
                    <div class="grid gap-4">
                        <div>
                            <p class="text-xs uppercase text-muted-foreground">{{ GraphExplorer::CATEGORIES[$hood['node']['category']] }}</p>
                            <h2 class="font-semibold">{{ $hood['node']['label'] }}</h2>
                            <p class="text-sm text-muted-foreground"><code>{{ $hood['node']['file'] }}@if ($hood['node']['line']):{{ $hood['node']['line'] }}@endif</code></p>
                        </div>

                        <div class="overflow-x-auto">
                            <svg viewBox="0 0 900 {{ $height }}" class="w-full min-w-[640px]" role="img" aria-label="Callers and dependencies of {{ $hood['node']['label'] }}">
                                @foreach ($left as $i => $edge)
                                    <line x1="250" y1="{{ $y($i, count($left)) }}" x2="350" y2="{{ $centerY }}" stroke="currentColor" class="text-border" />
                                @endforeach
                                @foreach ($right as $i => $edge)
                                    <line x1="550" y1="{{ $centerY }}" x2="650" y2="{{ $y($i, count($right)) }}" stroke="currentColor" class="text-border" />
                                @endforeach
                                @foreach ([[$left, 10], [$right, 650]] as [$column, $x])
                                    @foreach ($column as $i => $edge)
                                        <g wire:click="select(@js($edge['node']['id']))" class="cursor-pointer" wire:key="svg-{{ $x }}-{{ md5($edge['node']['id']) }}">
                                            <title>{{ $edge['kind'] }} · {{ $edge['node']['file'] }}</title>
                                            <rect x="{{ $x }}" y="{{ $y($i, count($column)) - 13 }}" width="240" height="26" rx="6" fill="{{ $colors[$edge['node']['category']] }}" fill-opacity="0.15" stroke="{{ $colors[$edge['node']['category']] }}" />
                                            <text x="{{ $x + 8 }}" y="{{ $y($i, count($column)) + 4 }}" font-size="12" fill="currentColor">{{ Str::limit($edge['node']['label'], 34) }}</text>
                                        </g>
                                    @endforeach
                                @endforeach
                                <rect x="350" y="{{ $centerY - 18 }}" width="200" height="36" rx="8" fill="{{ $colors[$hood['node']['category']] }}" />
                                <text x="450" y="{{ $centerY + 4 }}" font-size="13" text-anchor="middle" fill="white" font-weight="600">{{ Str::limit($hood['node']['label'], 28) }}</text>
                                <text x="130" y="12" font-size="11" text-anchor="middle" fill="currentColor" opacity="0.6">Callers ({{ count($left) }})</text>
                                <text x="770" y="12" font-size="11" text-anchor="middle" fill="currentColor" opacity="0.6">Dependencies ({{ count($right) }})</text>
                            </svg>
                        </div>

                        @foreach (['Members' => $hood['members'], 'Tests' => $hood['tests']] as $heading => $list)
                            @if ($list !== [])
                                <div class="grid gap-2">
                                    <h3 class="text-sm font-medium">{{ $heading }}</h3>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($list as $member)
                                            <button type="button" wire:click="select(@js($member['id']))" class="rounded border px-2 py-0.5 text-xs hover:bg-muted">{{ $member['label'] }}</button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </x-ui.card>
            @else
                <x-ui.card>
                    <p class="text-sm text-muted-foreground">Select a node to see its callers, dependencies, members and tests.</p>
                </x-ui.card>
            @endif
        </div>
    @endif
</div>
