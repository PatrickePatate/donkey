<?php

use App\Enums\LearningStatus;
use App\Models\Organization;
use App\Models\SecurityAdvisory;
use App\Models\WebhookEvent;
use App\Services\Knowledge\QualityMetrics;
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
     * Feedback on the last 30 days of posted findings.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function quality(): array
    {
        return app(QualityMetrics::class)->forOrganization($this->organization);
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

    @if ($this->pendingLearnings > 0)
        <x-ui.alert :title="$this->pendingLearnings.' proposed '.str('learning')->plural($this->pendingLearnings).' to review'">
            <x-slot:icon><x-ui.icon name="lightbulb" /></x-slot:icon>
            <p>Donkey turned feedback on its findings into rules for future reviews. They only apply once an admin approves them.</p>
            <div class="mt-2">
                <x-ui.button size="sm" variant="outline" :href="route('knowledge.index')" wire:navigate>Open the inbox</x-ui.button>
            </div>
        </x-ui.alert>
    @endif

    @php($quality = $this->quality)
    <x-ui.card title="Review quality" description="Feedback on findings posted in the last 30 days: suggestions applied, code fixed, 👍 and resolved threads count as accepted; dismissals and 👎 as rejected.">
        @if ($quality['posted'] === 0)
            <p class="py-6 text-center text-sm text-muted-foreground">No findings posted in the last 30 days.</p>
        @else
            <div class="grid gap-6">
                <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ([
                        ['Posted', $quality['posted']],
                        ['Acceptance', $quality['acceptance_rate'] !== null ? $quality['acceptance_rate'].'%' : '—'],
                        ['Accepted', $quality['accepted']],
                        ['Rejected', $quality['rejected']],
                        ['Merged untouched', $quality['ignored']],
                        ['Stopped by learnings', $quality['filtered_by_learnings']],
                    ] as [$label, $value])
                        <div class="grid gap-1">
                            <dt class="text-xs text-muted-foreground">{{ $label }}</dt>
                            <dd class="text-2xl font-semibold tabular-nums">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                <div class="grid gap-6 lg:grid-cols-2">
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

    @if ($this->security['checked'] > 0)
        <x-ui.card title="Security" :description="'Known vulnerabilities in the Composer dependencies of '.$this->security['checked'].' '.str('repository')->plural($this->security['checked']).'.'">
            <x-slot:actions>
                <x-ui.button variant="outline" size="sm" :href="route('security.index')" wire:navigate>View all</x-ui.button>
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
