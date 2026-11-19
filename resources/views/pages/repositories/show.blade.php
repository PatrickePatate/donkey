<?php

use App\Actions\ProposeDonkeySkill;
use App\Actions\SetAutoDocs;
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
            $this->dispatch('close-dialog');
            $this->dispatch('toast', message: 'GitHub refused the pull request. Check that the Donkey app may write contents and pull requests.', variant: 'destructive');

            return;
        }

        $this->redirect($url);
    }

    public function setAutoDocs(bool $enabled): void
    {
        $repository = $this->repository;
        Gate::authorize('update', $repository);

        if (!app(SetAutoDocs::class)->handle($repository, $enabled)) {
            return;
        }

        $this->dispatch('toast', message: 'Automatic documentation turned '.($enabled ? 'on' : 'off').'.', variant: 'success');
    }

    /**
     * @return Collection<int, PullRequest>
     */
    #[Computed]
    public function recentPullRequests(): Collection
    {
        return $this->repository->pullRequests()
            ->with('latestReviewRun')
            ->withCount(['findings as published_findings_count' => fn ($query) => $query->where('status', FindingStatus::Published)])
            ->latest('updated_at')
            ->limit(8)
            ->get();
    }

    /**
     * @return array{pull_requests: int, open_pull_requests: int, reviews: int, findings: int, advisories: int, severe_advisories: int}
     */
    #[Computed]
    public function stats(): array
    {
        return [
            'pull_requests' => $this->repository->pullRequests()->count(),
            'open_pull_requests' => $this->repository->pullRequests()->where('state', 'open')->count(),
            'advisories' => $this->repository->securityAdvisories()->count(),
            'severe_advisories' => $this->repository->securityAdvisories()->whereIn('severity', ['critical', 'high'])->count(),
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
        <div class="grid gap-2">
            <a href="{{ route('repositories.index') }}" wire:navigate class="text-sm text-muted-foreground hover:text-foreground">← Repositories</a>
            <div class="flex items-center gap-3">
                <span class="grid size-11 shrink-0 place-items-center rounded-xl border bg-muted/50 text-muted-foreground">
                    <x-ui.icon name="git-branch" class="size-5" />
                </span>
                <div class="grid">
                    <span class="text-sm text-muted-foreground">{{ Str::beforeLast($repository->full_name, '/') }}</span>
                    <h1 class="text-2xl leading-tight tracking-tight">{{ Str::afterLast($repository->full_name, '/') }}</h1>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <x-ui.badge variant="outline"><x-ui.icon :name="$repository->is_private ? 'lock' : 'globe'" /> {{ $repository->is_private ? 'Private' : 'Public' }}</x-ui.badge>
                <x-ui.badge variant="outline"><x-ui.icon name="git-branch" /> {{ $repository->default_branch }}</x-ui.badge>
                <x-ui.badge :variant="$repository->autoReviewEnabled() ? 'success' : 'secondary'">Auto-review {{ $repository->autoReviewEnabled() ? 'on' : 'off' }}</x-ui.badge>
                <x-ui.badge variant="outline">Risk threshold {{ $repository->effectiveRiskThreshold() }}</x-ui.badge>
            </div>
        </div>
        <div class="flex gap-2">
            @can('update', $repository)
                <x-ui.button variant="outline" x-on:click="$dispatch('open-dialog', 'add-claude-skill')"><x-ui.icon name="plus" /> Add Claude skill</x-ui.button>
                <x-ui.dialog name="add-claude-skill" title="Add the Donkey skill for Claude?">
                    <div class="grid gap-3 text-sm text-muted-foreground">
                        <p>Donkey will open a pull request on <span class="font-medium text-foreground">{{ $repository->full_name }}</span> that adds one file, <code class="rounded bg-muted px-1 py-0.5 text-xs">.claude/skills/donkey/SKILL.md</code>.</p>
                        <p>Once merged, Claude Code working in this repository knows how to use Donkey's MCP server: reviews, findings, learnings and the code graph. Nothing changes until you merge it.</p>
                    </div>
                    <div class="flex justify-end gap-2">
                        <x-ui.button variant="outline" x-on:click="$dispatch('close-dialog')" wire:loading.attr="disabled" wire:target="proposeSkill">Cancel</x-ui.button>
                        <x-ui.button wire:click="proposeSkill" wire:loading.attr="disabled" wire:target="proposeSkill">
                            <span wire:loading.remove wire:target="proposeSkill" class="inline-flex items-center gap-2"><x-ui.icon name="github" /> Open pull request</span>
                            <span wire:loading.inline-flex wire:target="proposeSkill" class="items-center gap-2"><x-ui.icon name="loader-circle" class="animate-spin" /> Opening pull request…</span>
                        </x-ui.button>
                    </div>
                </x-ui.dialog>
            @endcan
            <x-ui.button variant="outline" :href="rtrim(config('services.github.web_url'), '/').'/'.$repository->full_name" target="_blank" rel="noopener"><x-ui.icon name="github" /> View on GitHub</x-ui.button>
        </div>
    </div>

    <div class="flex gap-4 border-b text-sm" role="tablist">
        @foreach ($tabs as $key => $label)
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                wire:click="$set('tab', '{{ $key }}')"
                @class(['-mb-px cursor-pointer border-b-2 px-1 pb-2.5 font-medium transition-colors', 'border-foreground text-foreground' => $tab === $key, 'border-transparent text-muted-foreground hover:text-foreground' => $tab !== $key])
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
        @php($graph = $repository->latestGraph)
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="grid gap-2 rounded-xl border bg-card p-5 shadow-xs">
                <p class="flex items-center gap-2 text-sm text-muted-foreground"><x-ui.icon name="git-pull-request" /> Open pull requests</p>
                <p class="font-display text-3xl font-semibold tabular-nums">{{ $this->stats['open_pull_requests'] }}<span class="text-base font-medium text-muted-foreground"> / {{ $this->stats['pull_requests'] }}</span></p>
            </div>
            <div class="grid gap-2 rounded-xl border bg-card p-5 shadow-xs">
                <p class="flex items-center gap-2 text-sm text-muted-foreground"><x-ui.icon name="sparkles" /> Reviews</p>
                <p class="font-display text-3xl font-semibold tabular-nums">{{ number_format($this->stats['reviews']) }}</p>
            </div>
            <div class="grid gap-2 rounded-xl border bg-card p-5 shadow-xs">
                <p class="flex items-center gap-2 text-sm text-muted-foreground"><x-ui.icon name="message-square" /> Published findings</p>
                <p class="font-display text-3xl font-semibold tabular-nums">{{ number_format($this->stats['findings']) }}</p>
            </div>
            <button type="button" wire:click="$set('tab', 'security')" class="grid cursor-pointer gap-2 rounded-xl border bg-card p-5 text-left shadow-xs transition-colors hover:bg-muted/40">
                <p class="flex items-center gap-2 text-sm text-muted-foreground"><x-ui.icon name="shield" /> Vulnerabilities</p>
                <p @class(['font-display text-3xl font-semibold tabular-nums', 'text-destructive' => $this->stats['severe_advisories'] > 0])>{{ $this->stats['advisories'] }}</p>
                @if ($this->stats['severe_advisories'] > 0)
                    <x-ui.badge variant="destructive">{{ $this->stats['severe_advisories'] }} critical or high</x-ui.badge>
                @endif
            </button>
        </div>

        <div class="grid items-start gap-6 lg:grid-cols-3">
            <section class="grid gap-3 lg:col-span-2">
                <h2 class="text-sm text-muted-foreground">Recent pull requests</h2>
                <div class="table-wrap">
                    @if ($this->recentPullRequests->isEmpty())
                        <p class="py-10 text-center text-sm text-muted-foreground">No pull requests yet.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Pull request</th>
                                        <th>Author</th>
                                        <th>Review</th>
                                        <th class="num">Findings</th>
                                        <th class="num">Updated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->recentPullRequests as $pullRequest)
                                        @include('partials.pull-request-row', ['pullRequest' => $pullRequest, 'showRepository' => false])
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($this->stats['pull_requests'] > $this->recentPullRequests->count())
                            <div class="table-foot">
                                <a href="{{ route('pull-requests.index') }}" wire:navigate class="inline-flex items-center gap-1 font-medium text-foreground hover:underline">All pull requests <x-ui.icon name="arrow-right" /></a>
                            </div>
                        @endif
                    @endif
                </div>
            </section>

            <section class="grid gap-3">
                <h2 class="text-sm text-muted-foreground">Knowledge</h2>
                <ul class="divide-y rounded-xl border bg-card shadow-xs">
                    @php($docsReady = (bool) $repository->document?->generated_at)
                    @foreach ([
                        [
                            'icon' => 'book-open',
                            'title' => 'Documentation',
                            'ready' => $docsReady,
                            'detail' => $docsReady
                                ? 'Generated '.$repository->document->generated_at->diffForHumans().' from '.Str::substr((string) $repository->document->commit_sha, 0, 7)
                                : ($repository->auto_docs_enabled ? 'Generating…' : 'Off'),
                            'tab' => $docsReady ? 'documentation' : null,
                            'toggle' => true,
                        ],
                        [
                            'icon' => 'network',
                            'title' => 'Dependency graph',
                            'ready' => $graph?->status === \App\Enums\GraphStatus::Ready,
                            'detail' => $graph
                                ? $graph->status->label().($graph->built_at ? ' · built '.$graph->built_at->diffForHumans().' from '.Str::substr($graph->commit_sha, 0, 7) : '')
                                : 'Built when a pull request is reviewed or merged.',
                            'tab' => $graph?->status === \App\Enums\GraphStatus::Ready ? 'graph' : null,
                        ],
                    ] as $item)
                        <li class="flex items-center">
                            <button type="button" @if ($item['tab']) wire:click="$set('tab', '{{ $item['tab'] }}')" @else disabled @endif
                                class="group flex min-w-0 flex-1 items-start gap-3 p-4 text-left enabled:cursor-pointer enabled:hover:bg-muted/40">
                                <span @class(['grid size-8 shrink-0 place-items-center rounded-lg', 'bg-success/15 text-success' => $item['ready'], 'bg-muted text-muted-foreground' => ! $item['ready']])>
                                    <x-ui.icon :name="$item['icon']" />
                                </span>
                                <span class="grid min-w-0 flex-1 gap-0.5 text-sm">
                                    <span class="font-medium">{{ $item['title'] }}</span>
                                    <span class="text-xs text-muted-foreground">{{ $item['detail'] }}</span>
                                </span>
                                @if ($item['tab'] && ! ($item['toggle'] ?? false))
                                    <x-ui.icon name="arrow-right" class="mt-2 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                                @endif
                            </button>
                            @if ($item['toggle'] ?? false)
                                <x-ui.switch
                                    class="mr-4"
                                    wire:key="docs-switch-{{ $repository->auto_docs_enabled ? 1 : 0 }}"
                                    :checked="$repository->auto_docs_enabled"
                                    x-on:switch-change="$wire.setAutoDocs($event.detail)"
                                    :disabled="! auth()->user()->can('update', $repository)"
                                    aria-label="Automatic documentation"
                                />
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
    @endif
</div>
