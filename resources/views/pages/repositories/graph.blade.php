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
        if (!$this->graph) {
            return null;
        }

        $id = $this->focus !== '' ? $this->focus : app(GraphExplorer::class)->hub($this->graph);

        return $id !== null ? app(GraphExplorer::class)->neighbourhood($this->graph, $id) : null;
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
    // Muted chart tokens, readable in both themes.
    $colors = ['module' => 'var(--muted-foreground)', 'class' => 'var(--chart-1)', 'service' => 'var(--chart-2)', 'model' => 'var(--chart-4)', 'endpoint' => 'var(--chart-3)', 'method' => 'var(--chart-6)', 'test' => 'var(--chart-5)'];
@endphp

<div class="grid gap-6">
    @unless ($embedded)
        <div class="grid gap-1">
            <a href="{{ route('repositories.show', $this->repository) }}" wire:navigate class="text-sm text-muted-foreground hover:text-foreground">← {{ $this->repository->full_name }}</a>
            <h1 class="text-2xl tracking-tight">Dependency graph</h1>
        </div>
    @endunless

    @if (! $this->graph)
        <div class="flex flex-col items-center gap-2 rounded-xl border border-dashed py-12 text-center">
            <x-ui.icon name="network" class="size-8 text-muted-foreground" />
            <p class="font-medium">No graph yet</p>
            <p class="max-w-sm text-sm text-muted-foreground">No code graph has been built for this repository yet. One is built automatically when a pull request is reviewed or merged.</p>
        </div>
    @else
        <div class="table-wrap grid lg:grid-cols-[17rem_minmax(0,1fr)]">
            <aside class="flex min-w-0 flex-col border-b lg:border-r lg:border-b-0">
                <div class="grid gap-3 border-b p-3">
                    <label class="table-search max-w-none">
                        <x-ui.icon name="search" />
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Classes, methods, files…" aria-label="Search the graph">
                    </label>
                    <div class="flex flex-wrap gap-1">
                        @foreach (GraphExplorer::CATEGORIES as $key => $label)
                            <button type="button" wire:click="filter('{{ $key }}')" aria-pressed="{{ $category === $key ? 'true' : 'false' }}"
                                @class(['inline-flex cursor-pointer items-center gap-1.5 rounded-md border px-2 py-0.5 text-xs transition-colors', 'border-foreground/30 bg-muted font-medium' => $category === $key, 'text-muted-foreground hover:text-foreground' => $category !== $key])>
                                <span class="size-2 rounded-full" style="background: {{ $colors[$key] }}"></span>
                                {{ $label }} <span class="tabular-nums opacity-70">{{ $this->counts[$key] ?? 0 }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
                <ul class="grid max-h-[34rem] content-start gap-0.5 overflow-y-auto p-2 text-sm">
                    @forelse ($this->results as $result)
                        <li wire:key="result-{{ md5($result['id']) }}">
                            <button type="button" wire:click="select(@js($result['id']))" title="{{ $result['file'] }}"
                                @class(['flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-left', 'bg-accent font-medium' => ($hood = $this->neighbourhood) && $hood['node']['id'] === $result['id'], 'hover:bg-muted/60' => true])>
                                <span class="size-2 shrink-0 rounded-full" style="background: {{ $colors[$result['category']] }}"></span>
                                <span class="truncate font-mono text-xs">{{ $result['label'] }}</span>
                            </button>
                        </li>
                    @empty
                        <li class="px-2 py-6 text-center text-muted-foreground">No matching nodes.</li>
                    @endforelse
                </ul>
                <p class="mt-auto border-t px-3 py-2 text-xs text-muted-foreground">
                    {{ number_format($this->graph->nodes_count) }} nodes · {{ number_format($this->graph->edges_count) }} edges · built {{ $this->graph->built_at?->diffForHumans(short: true) }} from <code>{{ Str::substr($this->graph->commit_sha, 0, 7) }}</code>
                </p>
            </aside>

            <section class="min-w-0">
                @if ($hood = $this->neighbourhood)
                    @php
                        $left = $hood['callers'];
                        $right = $hood['dependencies'];
                        $rows = max(count($left), count($right), 1);
                        $height = max(220, $rows * 46 + 60);
                        $centerY = $height / 2;
                        $y = fn (int $index, int $count) => $count === 1 ? $centerY : 40 + $index * (($height - 80) / max($count - 1, 1));
                        $nodeColor = $colors[$hood['node']['category']];
                    @endphp
                    <div class="flex flex-wrap items-start justify-between gap-3 border-b p-4">
                        <div class="grid min-w-0 gap-1">
                            <span class="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                <span class="size-2 rounded-full" style="background: {{ $nodeColor }}"></span>
                                {{ GraphExplorer::CATEGORIES[$hood['node']['category']] }}
                            </span>
                            <h2 class="truncate font-mono text-base">{{ $hood['node']['label'] }}</h2>
                            <code class="truncate text-xs text-muted-foreground">{{ $hood['node']['file'] }}@if ($hood['node']['line']):{{ $hood['node']['line'] }}@endif</code>
                        </div>
                        <div class="flex gap-2 text-xs">
                            <x-ui.badge variant="outline">{{ count($left) }} {{ Str::plural('caller', count($left)) }}</x-ui.badge>
                            <x-ui.badge variant="outline">{{ count($right) }} {{ Str::plural('dependency', count($right)) }}</x-ui.badge>
                            <x-ui.badge :variant="$hood['tests'] === [] && $hood['node']['category'] !== 'test' ? 'warning' : 'success'">{{ count($hood['tests']) }} {{ Str::plural('test', count($hood['tests'])) }}</x-ui.badge>
                        </div>
                    </div>

                    <div class="overflow-x-auto bg-[radial-gradient(var(--border)_1px,transparent_1px)] [background-size:16px_16px]">
                        <svg viewBox="0 0 900 {{ $height }}" class="w-full min-w-[640px]" role="img" aria-label="Callers and dependencies of {{ $hood['node']['label'] }}">
                            <defs>
                                <marker id="graph-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                                    <path d="M0,0 L10,5 L0,10 z" class="fill-muted-foreground/60" />
                                </marker>
                            </defs>
                            @foreach ($left as $i => $edge)
                                @php($ly = $y($i, count($left)))
                                <path d="M 250 {{ $ly }} C 300 {{ $ly }}, 290 {{ $centerY }}, 333 {{ $centerY }}" fill="none" class="stroke-muted-foreground/40" stroke-width="1.25" marker-end="url(#graph-arrow)" />
                            @endforeach
                            @foreach ($right as $i => $edge)
                                @php($ry = $y($i, count($right)))
                                <path d="M 567 {{ $centerY }} C 610 {{ $centerY }}, 600 {{ $ry }}, 648 {{ $ry }}" fill="none" class="stroke-muted-foreground/40" stroke-width="1.25" marker-end="url(#graph-arrow)" />
                            @endforeach
                            @foreach ([[$left, 20], [$right, 650]] as [$column, $x])
                                @foreach ($column as $i => $edge)
                                    @php($ny = $y($i, count($column)))
                                    <g wire:click="select(@js($edge['node']['id']))" class="group cursor-pointer" wire:key="svg-{{ $x }}-{{ md5($edge['node']['id']) }}">
                                        <title>{{ $edge['kind'] }} · {{ $edge['node']['file'] }}</title>
                                        <rect x="{{ $x }}" y="{{ $ny - 15 }}" width="230" height="30" rx="8" class="fill-card stroke-border group-hover:stroke-muted-foreground" />
                                        <circle cx="{{ $x + 14 }}" cy="{{ $ny }}" r="4" style="fill: {{ $colors[$edge['node']['category']] }}" />
                                        <text x="{{ $x + 26 }}" y="{{ $ny + 4 }}" font-size="11.5" class="fill-foreground font-mono">{{ Str::limit($edge['node']['label'], 30) }}</text>
                                    </g>
                                @endforeach
                            @endforeach
                            <rect x="335" y="{{ $centerY - 22 }}" width="230" height="44" rx="10" class="fill-card" style="stroke: {{ $nodeColor }}" stroke-width="2" />
                            <rect x="335" y="{{ $centerY - 22 }}" width="230" height="44" rx="10" style="fill: {{ $nodeColor }}" fill-opacity="0.12" />
                            <text x="450" y="{{ $centerY + 4 }}" font-size="12.5" text-anchor="middle" font-weight="600" class="fill-foreground font-mono">{{ Str::limit($hood['node']['label'], 28) }}</text>
                            @if ($left === [])
                                <text x="135" y="{{ $centerY + 4 }}" font-size="11" text-anchor="middle" class="fill-muted-foreground">No callers</text>
                            @endif
                            @if ($right === [])
                                <text x="765" y="{{ $centerY + 4 }}" font-size="11" text-anchor="middle" class="fill-muted-foreground">No dependencies</text>
                            @endif
                            <text x="135" y="18" font-size="10.5" text-anchor="middle" letter-spacing="0.06em" class="fill-muted-foreground">CALLERS</text>
                            <text x="765" y="18" font-size="10.5" text-anchor="middle" letter-spacing="0.06em" class="fill-muted-foreground">DEPENDENCIES</text>
                        </svg>
                    </div>

                    @if ($hood['members'] !== [] || $hood['tests'] !== [])
                        <div class="grid gap-4 border-t p-4">
                            @foreach (['Members' => $hood['members'], 'Tests' => $hood['tests']] as $heading => $list)
                                @if ($list !== [])
                                    <div class="grid gap-2">
                                        <h3 class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ $heading }}</h3>
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach ($list as $member)
                                                <button type="button" wire:click="select(@js($member['id']))" class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border bg-card px-2 py-0.5 font-mono text-xs hover:bg-muted">
                                                    <span class="size-1.5 rounded-full" style="background: {{ $colors[$member['category']] }}"></span>
                                                    {{ $member['label'] }}
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif
                @else
                    <p class="p-10 text-center text-sm text-muted-foreground">Select a node to see its callers, dependencies, members and tests.</p>
                @endif
            </section>
        </div>
    @endif
</div>
