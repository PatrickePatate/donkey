<?php

use App\Enums\LearningStatus;
use App\Models\Organization;
use App\Models\SecurityAdvisory;
use App\Models\WebhookEvent;
use App\Services\Knowledge\QualityMetrics;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    /**
     * Length of the period the KPIs and review quality cover.
     */
    #[Url]
    public int $days = 30;

    /**
     * @var list<int>
     */
    public const PERIODS = [7, 30, 90];

    public function updatedDays(): void
    {
        if (!in_array($this->days, self::PERIODS, true)) {
            $this->days = 30;
        }
    }

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
     * Feedback on the findings posted during the period.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function quality(): array
    {
        return app(QualityMetrics::class)->forOrganization($this->organization, $this->period());
    }

    /**
     * Acceptance rate change against the previous period, in points.
     */
    #[Computed]
    public function acceptanceDelta(): ?int
    {
        $previous = app(QualityMetrics::class)->forOrganization($this->organization, $this->period(), $this->period())['acceptance_rate'];

        return $previous !== null && $this->quality['acceptance_rate'] !== null ? $this->quality['acceptance_rate'] - $previous : null;
    }

    private function period(): int
    {
        return in_array($this->days, self::PERIODS, true) ? $this->days : 30;
    }

    /**
     * Repositories with known vulnerabilities and the packages behind them, worst first.
     *
     * @return array{checked: int, affected: int, severe: int, packages: list<array{package: string, severity: ?string, repositories: int}>}
     */
    #[Computed]
    public function security(): array
    {
        $repositoryIds = $this->organization->repositories()->select('id');
        $advisories = SecurityAdvisory::query()->whereIn('repository_id', $repositoryIds);
        $rank = "min(case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 when 'low' then 3 else 4 end)";

        return [
            'checked' => $this->organization->repositories()->whereNotNull('composer_packages_count')->count(),
            'affected' => (clone $advisories)->distinct()->count('repository_id'),
            'severe' => (clone $advisories)->whereIn('severity', ['critical', 'high'])->distinct()->count('repository_id'),
            'packages' => (clone $advisories)->toBase()
                ->selectRaw("package, {$rank} as severity_rank, count(distinct repository_id) as repositories")
                ->groupBy('package')
                ->orderBy('severity_rank')
                ->orderByDesc('repositories')
                ->orderBy('package')
                ->limit(6)
                ->get()
                ->map(fn ($row): array => ['package' => $row->package, 'severity' => ['critical', 'high', 'medium', 'low'][$row->severity_rank] ?? null, 'repositories' => (int) $row->repositories])
                ->all(),
        ];
    }

    #[Computed]
    public function pendingLearnings(): int
    {
        return $this->organization->learnings()->where('status', LearningStatus::Proposed)->count();
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
            ->get(['id', 'event', 'action', 'status', 'payload', 'created_at']);
    }
};
?>

<div class="grid gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="grid gap-1">
            <h1 class="text-2xl tracking-tight">Dashboard</h1>
            <p class="text-sm text-muted-foreground">How Donkey's reviews landed with {{ $this->organization->name }}.</p>
        </div>
        <div role="radiogroup" aria-label="Period" class="inline-flex rounded-lg bg-muted p-1 text-sm">
            @foreach ($this::PERIODS as $period)
                <button type="button" role="radio" aria-checked="{{ $days === $period ? 'true' : 'false' }}" wire:click="$set('days', {{ $period }})"
                    @class(['h-7 cursor-pointer rounded-md px-3 font-medium transition-colors', 'bg-background text-foreground shadow-xs' => $days === $period, 'text-muted-foreground hover:text-foreground' => $days !== $period])>{{ $period }} days</button>
            @endforeach
        </div>
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

    @php($quality = $this->quality)
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="grid gap-2 rounded-xl border bg-card p-5 shadow-xs">
            <p class="flex items-center gap-2 text-sm text-muted-foreground"><x-ui.icon name="circle-check" /> Acceptance rate</p>
            <p class="font-display text-3xl font-semibold tabular-nums">{{ $quality['acceptance_rate'] !== null ? $quality['acceptance_rate'].'%' : '—' }}</p>
            @if ($this->acceptanceDelta !== null)
                <x-ui.badge :variant="$this->acceptanceDelta >= 0 ? 'success' : 'destructive'" title="Against the previous {{ $days }} days">
                    <x-ui.icon :name="$this->acceptanceDelta >= 0 ? 'trending-up' : 'trending-down'" /> {{ sprintf('%+d', $this->acceptanceDelta) }} pts
                </x-ui.badge>
            @else
                <x-ui.badge variant="outline">No feedback yet</x-ui.badge>
            @endif
        </div>
        <div class="grid gap-2 rounded-xl border bg-card p-5 shadow-xs">
            <p class="flex items-center gap-2 text-sm text-muted-foreground"><x-ui.icon name="message-square" /> Findings posted</p>
            <p class="font-display text-3xl font-semibold tabular-nums">{{ $quality['posted'] }}</p>
            <x-ui.badge variant="secondary">{{ $quality['pull_requests'] }} {{ str('pull request')->plural($quality['pull_requests']) }}</x-ui.badge>
        </div>
        <div class="grid gap-2 rounded-xl border bg-card p-5 shadow-xs">
            <p class="flex items-center gap-2 text-sm text-muted-foreground"><x-ui.icon name="git-branch" /> Auto-review on</p>
            <p class="font-display text-3xl font-semibold tabular-nums">{{ $this->stats['auto_review'] }}<span class="text-base font-medium text-muted-foreground"> / {{ $this->stats['repositories'] }}</span></p>
            <x-ui.badge variant="outline">{{ str('repository')->plural($this->stats['repositories']) }}</x-ui.badge>
        </div>
        <div class="grid gap-2 rounded-xl border bg-card p-5 shadow-xs">
            <p class="flex items-center gap-2 text-sm text-muted-foreground"><x-ui.icon name="shield" /> Repos with severe vulns</p>
            <p @class(['font-display text-3xl font-semibold tabular-nums', 'text-destructive' => $this->security['severe'] > 0])>{{ $this->security['severe'] }}</p>
            <x-ui.badge :variant="$this->security['affected'] > 0 ? 'warning' : 'outline'">{{ $this->security['affected'] }} {{ str('project')->plural($this->security['affected']) }} affected</x-ui.badge>
        </div>
    </div>

    @if ($this->pendingLearnings > 0)
        <x-ui.alert :title="$this->pendingLearnings.' proposed '.str('learning')->plural($this->pendingLearnings).' to review'">
            <x-slot:icon><x-ui.icon name="lightbulb" /></x-slot:icon>
            <p>Donkey turned feedback on its findings into rules for future reviews. They only apply once an admin approves them.</p>
            <div class="mt-2">
                <x-ui.button size="sm" variant="outline" :href="route('knowledge.index')" wire:navigate>Open the inbox <x-ui.icon name="arrow-right" /></x-ui.button>
            </div>
        </x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-2" title="Review quality" :description="'How the team responded to findings posted in the last '.$days.' days.'">
            <x-slot:actions>
                @php($handle = '@'.$this->organization->bot_handle)
                <div x-data="{ open: false }" class="relative" x-on:keydown.escape="open = false" x-on:click.outside="open = false">
                    <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-label="How feedback is counted" class="grid size-8 cursor-pointer place-items-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground">
                        <x-ui.icon name="circle-help" />
                    </button>
                    <div x-show="open" x-transition.opacity x-cloak role="dialog" aria-label="Signals Donkey understands" class="absolute top-full right-0 z-30 mt-1 grid w-80 gap-3 rounded-lg border bg-popover p-4 text-sm text-popover-foreground shadow-md">
                        <p class="font-medium">Signals Donkey understands</p>
                        @foreach ([
                            'Accepted' => [['👍', 'Thumbs up on the finding comment'], ['Suggestion applied', 'The suggested change was committed'], ['Fixed', 'The flagged code changed in a later commit'], ['Thread resolved', 'Someone resolved the review thread']],
                            'Rejected' => [['👎', 'Thumbs down on the finding comment'], [$handle.' ignore: <why>', 'Reply in the thread to dismiss it; the reason feeds learnings']],
                            'Neutral' => [['Merged untouched', 'The pull request merged without any reaction']],
                        ] as $group => $signals)
                            <div class="grid gap-1.5">
                                <p @class(['text-xs font-medium tracking-wide uppercase', 'text-success' => $group === 'Accepted', 'text-destructive' => $group === 'Rejected', 'text-muted-foreground' => $group === 'Neutral'])>{{ $group }}</p>
                                <ul class="grid gap-1.5">
                                    @foreach ($signals as [$signal, $meaning])
                                        <li class="grid gap-0.5">
                                            <code class="w-fit rounded bg-muted px-1.5 py-0.5 text-xs">{{ $signal }}</code>
                                            <span class="text-xs text-muted-foreground">{{ $meaning }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                        <p class="border-t pt-3 text-xs text-muted-foreground">A finding counts once: accepted if anything positive happened, otherwise rejected or merged untouched. Rejections feed the learnings Donkey proposes.</p>
                    </div>
                </div>
            </x-slot:actions>
            @if ($quality['posted'] === 0)
                <p class="py-6 text-center text-sm text-muted-foreground">No findings posted in the last {{ $days }} days.</p>
            @else
                <div class="grid gap-6">
                    <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
                        @foreach ([
                            ['Posted', $quality['posted'], null],
                            ['Acceptance', $quality['acceptance_rate'] !== null ? $quality['acceptance_rate'].'%' : '—', 'Accepted out of findings that got feedback.'],
                            ['Accepted', $quality['accepted'], 'Suggestion applied, code fixed, thumbs up or thread resolved.'],
                            ['Rejected', $quality['rejected'], 'Dismissed or thumbs down.'],
                            ['Merged untouched', $quality['ignored'], 'Merged without any reaction to the finding.'],
                            ['Stopped by learnings', $quality['filtered_by_learnings'], 'Not posted because an approved learning ruled it out.'],
                        ] as [$label, $value, $hint])
                            <div class="grid gap-1">
                                <dt @class(['text-xs text-muted-foreground', 'w-fit cursor-help underline decoration-dotted underline-offset-2' => $hint]) @if ($hint) title="{{ $hint }}" @endif>{{ $label }}</dt>
                                <dd class="text-2xl font-semibold tabular-nums">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <x-ui.progress-stacked label="Findings by category" :segments="collect($quality['by_category'])->map(fn (array $row): array => ['label' => $row['label'], 'value' => $row['posted']])->all()" />

                    <div class="grid gap-6 xl:grid-cols-2">
                        @foreach (['By category' => $quality['by_category'], 'By reviewer' => $quality['by_reviewer']] as $title => $rows)
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b text-left text-muted-foreground">
                                            <th class="h-9 px-2 font-medium">{{ $title }}</th>
                                            <th class="h-9 px-2 text-right font-medium">Posted</th>
                                            <th class="h-9 px-2 text-right font-medium">Accepted</th>
                                            <th class="h-9 px-2 text-right font-medium">Rejected</th>
                                            <th class="h-9 w-32 px-2 font-medium"><span class="sr-only">Share</span></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($rows as $row)
                                            <tr class="border-b last:border-0">
                                                <td class="p-2">{{ $row['label'] }}</td>
                                                <td class="p-2 text-right tabular-nums">{{ $row['posted'] }}</td>
                                                <td class="p-2 text-right tabular-nums">{{ $row['accepted'] }}</td>
                                                <td class="p-2 text-right tabular-nums">{{ $row['rejected'] }}</td>
                                                <td class="p-2">
                                                    <div class="flex h-1.5 overflow-hidden rounded-full bg-muted" title="{{ $row['accepted'] }} accepted, {{ $row['rejected'] }} rejected, {{ $row['ignored'] }} merged untouched">
                                                        <span class="bg-success" style="width: {{ 100 * $row['accepted'] / max(1, $row['posted']) }}%"></span>
                                                        <span class="bg-destructive" style="width: {{ 100 * $row['rejected'] / max(1, $row['posted']) }}%"></span>
                                                        <span class="bg-muted-foreground/40" style="width: {{ 100 * $row['ignored'] / max(1, $row['posted']) }}%"></span>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-ui.card>

        <div class="relative">
            {{-- Absolutely placed on large screens so the review quality card alone sets the row height; the feed scrolls inside. --}}
    <x-ui.card class="max-h-[28rem] lg:absolute lg:inset-0 lg:max-h-none [&>div:last-child]:min-h-0 [&>div:last-child]:overflow-y-auto" title="Live activity" description="GitHub events, updated in real time.">
                @if ($this->recentEvents->isEmpty())
                    <p class="py-6 text-center text-sm text-muted-foreground">No events yet.</p>
                @else
                    <ol class="grid">
                        @foreach ($this->recentEvents as $event)
                            @php($activity = $event->activity())
                            <li wire:key="event-{{ $event->id }}" class="relative flex gap-3 pb-4 last:pb-0">
                                @unless ($loop->last)
                                    <span aria-hidden="true" class="absolute top-8 bottom-0 left-3.5 w-px bg-border"></span>
                                @endunless
                                <span @class([
                                    'relative grid size-7 shrink-0 place-items-center rounded-full [&>svg]:size-3.5',
                                    'bg-success/15 text-success' => $activity['tone'] === 'success',
                                    'bg-destructive/10 text-destructive' => $activity['tone'] === 'danger',
                                    'bg-warning/25 text-foreground' => $activity['tone'] === 'warning',
                                    'bg-[color-mix(in_oklch,var(--chart-1)_15%,transparent)] text-[var(--chart-1)]' => $activity['tone'] === 'info',
                                    'border bg-muted text-muted-foreground' => $activity['tone'] === 'neutral',
                                ])>
                                    <x-ui.icon :name="$activity['icon']" />
                                </span>
                                <p class="min-w-0 pt-1 text-sm leading-5">
                                    <span @class(['font-medium', 'text-destructive' => $activity['tone'] === 'danger'])>{{ $activity['title'] }}</span>
                                    @if ($activity['subject'])
                                        <span class="break-all text-muted-foreground">{{ $activity['subject'] }}</span>
                                    @endif
                                    <span class="text-xs whitespace-nowrap text-muted-foreground">· {{ $event->created_at->diffForHumans(short: true) }}</span>
                                </p>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>
        </div>
    </div>

    @if ($this->security['checked'] > 0)
        <x-ui.card title="Security" :description="'Known vulnerabilities in the Composer dependencies of '.$this->security['checked'].' '.str('repository')->plural($this->security['checked']).'.'">
            <x-slot:actions>
                <x-ui.button variant="outline" size="sm" :href="route('security.index')" wire:navigate>View all <x-ui.icon name="arrow-right" /></x-ui.button>
            </x-slot:actions>

            @if ($this->security['affected'] === 0)
                <p class="flex items-center gap-2 text-sm text-muted-foreground"><x-ui.icon name="check" class="text-success" /> No known vulnerabilities.</p>
            @else
                <div class="grid gap-6 md:grid-cols-[14rem_1fr]">
                    <div class="grid content-start gap-4">
                        <div class="grid gap-1">
                            <span class="text-3xl font-semibold tabular-nums">{{ $this->security['affected'] }}<span class="text-base font-normal text-muted-foreground"> / {{ $this->security['checked'] }}</span></span>
                            <span class="text-sm text-muted-foreground">{{ str('project')->plural($this->security['affected']) }} with vulnerable packages</span>
                        </div>
                        <div class="grid gap-1">
                            <span @class(['text-3xl font-semibold tabular-nums', 'text-destructive' => $this->security['severe'] > 0])>{{ $this->security['severe'] }}</span>
                            <span class="text-sm text-muted-foreground">with a critical or high one</span>
                        </div>
                    </div>

                    <div class="grid content-start gap-2">
                        <p class="text-xs font-medium text-muted-foreground">Packages to update</p>
                        <ul class="divide-y">
                            @foreach ($this->security['packages'] as $row)
                                @php($severity = \App\Enums\FindingSeverity::tryFrom((string) $row['severity']))
                                <li class="flex items-center gap-3 py-2 text-sm">
                                    @if ($severity)
                                        <x-ui.badge :variant="$severity->badgeVariant()">{{ $severity->label() }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="outline">Unrated</x-ui.badge>
                                    @endif
                                    <a href="{{ route('security.index', ['search' => $row['package']]) }}" wire:navigate class="truncate font-mono text-xs font-medium hover:underline">{{ $row['package'] }}</a>
                                    <span class="ml-auto shrink-0 text-xs text-muted-foreground">{{ $row['repositories'] }} {{ str('project')->plural($row['repositories']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif
        </x-ui.card>
    @endif
</div>
