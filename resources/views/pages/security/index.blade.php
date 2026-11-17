<?php

use App\Enums\FindingSeverity;
use App\Jobs\ScanRepositoryDependencies;
use App\Models\Organization;
use App\Models\Repository;
use App\Models\SecurityAdvisory;
use App\Services\Audit\AuditLog;
use App\Services\GitHub\PullRequestClient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Security')] class extends Component
{
    use WithPagination;

    /** Set when embedded in a repository page: only that repository is shown. */
    public ?int $repositoryId = null;

    public bool $embedded = false;

    public string $securityBranch = '';

    #[Url(except: '')]
    public string $severity = '';

    #[Url(except: '')]
    public string $search = '';

    public function mount(?int $repository = null): void
    {
        $this->repositoryId = $repository;
        $this->securityBranch = (string) $this->repositories->first()?->security_branch;
    }

    public function updatedSeverity(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
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
            ->when($this->repositoryId, fn (Builder $query, int $id) => $query->whereKey($id))
            ->orderBy('full_name')
            ->get();
    }

    /**
     * @return Builder<SecurityAdvisory>
     */
    private function advisoryQuery(): Builder
    {
        return SecurityAdvisory::query()->whereIn('repository_id', $this->repositories->modelKeys());
    }

    /**
     * Advisory counts by severity value ('' for unrated).
     *
     * @return array<string, int>
     */
    #[Computed]
    public function severityCounts(): array
    {
        $counts = $this->advisoryQuery()->toBase()->selectRaw('severity, count(*) as aggregate')->groupBy('severity')->pluck('aggregate', 'severity');

        return collect(FindingSeverity::cases())
            ->mapWithKeys(fn (FindingSeverity $severity): array => [$severity->value => (int) ($counts[$severity->value] ?? 0)])
            ->put('', (int) ($counts[''] ?? 0))
            ->all();
    }

    /**
     * The most exposed repositories with their counts per severity.
     *
     * @return list<array{repository: Repository, total: int, counts: array<string, int>}>
     */
    #[Computed]
    public function exposure(): array
    {
        $rows = $this->advisoryQuery()->toBase()->selectRaw('repository_id, severity, count(*) as aggregate')->groupBy('repository_id', 'severity')->get();
        $repositories = $this->repositories->keyBy('id');

        return $rows->groupBy('repository_id')
            ->map(fn ($group, $id): array => [
                'repository' => $repositories[$id],
                'total' => (int) $group->sum('aggregate'),
                'counts' => $group->mapWithKeys(fn ($row): array => [(string) $row->severity => (int) $row->aggregate])->all(),
            ])
            ->sortByDesc(fn (array $row): array => [$row['counts']['critical'] ?? 0, $row['counts']['high'] ?? 0, $row['total']])
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return LengthAwarePaginator<int, SecurityAdvisory>
     */
    #[Computed]
    public function advisories(): LengthAwarePaginator
    {
        return $this->advisoryQuery()
            ->with('repository')
            ->when($this->severity === 'unrated', fn (Builder $query) => $query->whereNull('severity'))
            ->when(FindingSeverity::tryFrom($this->severity), fn (Builder $query, FindingSeverity $severity) => $query->where('severity', $severity))
            ->when($this->search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('package', "%{$this->search}%")
                ->orWhereLike('title', "%{$this->search}%")
                ->orWhereLike('cve', "%{$this->search}%")))
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 when 'low' then 3 else 4 end")
            ->orderBy('package')
            ->paginate(25);
    }

    public function saveSecurityBranch(PullRequestClient $client): void
    {
        $repository = $this->repositories->sole();
        Gate::authorize('update', $repository);

        $this->validate(['securityBranch' => ['nullable', 'string', 'max:255', 'not_regex:/\\s|\\.\\.|^-/']]);
        $branch = trim($this->securityBranch);

        if ($branch !== '' && $branch !== $repository->default_branch) {
            try {
                $client->branchHead($repository, $branch);
            } catch (\Throwable) {
                $this->addError('securityBranch', "Couldn't find the branch {$branch} on GitHub.");

                return;
            }
        }

        $repository->update(['security_branch' => $branch === '' || $branch === $repository->default_branch ? null : $branch]);

        if ($repository->wasChanged('security_branch')) {
            app(AuditLog::class)->record($this->organization, 'repository.security_branch_changed', $repository, AuditLog::changes($repository, ['security_branch']));
            ScanRepositoryDependencies::dispatch($repository);
        }

        $this->securityBranch = (string) $repository->security_branch;
        unset($this->repositories);
        $this->dispatch('toast', message: "Monitoring {$repository->securityBranch()}. Checking it now…", variant: 'success');
    }

    public function scan(): void
    {
        foreach ($this->repositories as $repository) {
            Gate::authorize('update', $repository);
        }

        $this->repositories->each(fn (Repository $repository) => ScanRepositoryDependencies::dispatch($repository));

        $this->dispatch('toast', message: $this->repositoryId ? 'Checking the dependencies…' : "Checking the dependencies of {$this->repositories->count()} ".str('repository')->plural($this->repositories->count()).'…');
    }
};
?>

@php
    $bars = ['critical' => 'bg-destructive', 'high' => 'bg-destructive/60', 'medium' => 'bg-warning', 'low' => 'bg-muted-foreground/40', '' => 'bg-border'];
    $scanned = $this->repositories->whereNotNull('dependencies_scanned_at');
    $withComposer = $scanned->whereNotNull('composer_packages_count');
    $failed = $this->repositories->whereNotNull('dependencies_scan_error');
    $lastScan = $scanned->max('dependencies_scanned_at');
    $total = array_sum($this->severityCounts);
    $canScan = $this->repositories->isNotEmpty() && $this->repositories->every(fn ($repository) => auth()->user()->can('update', $repository));
@endphp

<div class="grid gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="grid gap-1">
            @unless ($embedded)
                <h1 class="text-2xl font-semibold tracking-tight">Security</h1>
            @endunless
            <p class="text-sm text-muted-foreground">
                @if ($repositoryId && $this->repositories->first())
                    Known vulnerabilities in the Composer packages locked on <code>{{ $this->repositories->first()->securityBranch() }}</code>, from the Packagist security advisories.
                @else
                    Known vulnerabilities in the Composer packages locked on each repository's monitored branch, from the Packagist security advisories.
                @endif
                Checked daily and after each merge.
                @if ($lastScan)
                    Last check {{ $lastScan->diffForHumans() }}.
                @endif
            </p>
        </div>

        @if ($canScan)
            <x-ui.button variant="outline" wire:click="scan">
                <x-ui.icon name="refresh" class="hidden animate-spin" wire:loading.class.remove="hidden" wire:target="scan" />
                <x-ui.icon name="shield" wire:loading.remove wire:target="scan" />
                Check now
            </x-ui.button>
        @endif
    </div>

    @if ($repositoryId && $canScan)
        <form wire:submit="saveSecurityBranch" class="flex flex-wrap items-start gap-2">
            <x-ui.field label="Monitored branch" for="securityBranch" error="securityBranch" :hint="'Empty uses the default branch ('.$this->repositories->first()->default_branch.').'" class="w-full max-w-xs">
                <x-ui.input id="securityBranch" wire:model="securityBranch" class="font-mono" :placeholder="$this->repositories->first()->default_branch" />
            </x-ui.field>
            <x-ui.button type="submit" variant="outline" class="sm:mt-6">Save</x-ui.button>
        </form>
    @endif

    @if ($failed->isNotEmpty())
        <x-ui.alert title="Some checks failed" variant="destructive">
            <x-slot:icon><x-ui.icon name="shield" /></x-slot:icon>
            <p>{{ $failed->pluck('full_name')->join(', ') }}: {{ $failed->first()->dependencies_scan_error }}</p>
        </x-ui.alert>
    @endif

    @if ($scanned->isEmpty())
        <x-ui.card>
            <div class="flex flex-col items-center gap-2 py-10 text-center">
                <x-ui.icon name="shield" class="size-8 text-muted-foreground" />
                <p class="font-medium">Not checked yet</p>
                <p class="max-w-sm text-sm text-muted-foreground">The first check runs tonight. Use "Check now" to run it right away.</p>
            </div>
        </x-ui.card>
    @elseif ($withComposer->isEmpty())
        <x-ui.card>
            <div class="flex flex-col items-center gap-2 py-10 text-center">
                <x-ui.icon name="shield" class="size-8 text-muted-foreground" />
                <p class="font-medium">No composer.lock found</p>
                <p class="max-w-sm text-sm text-muted-foreground">Only PHP projects with a committed composer.lock at the repository root are checked.</p>
            </div>
        </x-ui.card>
    @else
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            @foreach ([...array_map(fn ($case) => [$case->value, $case->label()], FindingSeverity::cases()), ['unrated', 'Unrated']] as [$value, $label])
                @php($count = $this->severityCounts[$value === 'unrated' ? '' : $value])
                <button
                    type="button"
                    wire:click="$set('severity', '{{ $severity === $value ? '' : $value }}')"
                    @class([
                        'grid gap-1 rounded-xl border bg-card p-4 text-left shadow-xs transition-colors hover:bg-accent',
                        'ring-2 ring-ring' => $severity === $value,
                    ])
                >
                    <span class="flex items-center gap-2 text-sm text-muted-foreground">
                        <span @class(['size-2 rounded-full', $bars[$value === 'unrated' ? '' : $value]])></span>
                        {{ $label }}
                    </span>
                    <span @class(['text-2xl font-semibold tabular-nums', 'text-destructive' => in_array($value, ['critical', 'high'], true) && $count > 0])>{{ $count }}</span>
                </button>
            @endforeach
        </div>

        @if (! $repositoryId && $this->exposure !== [])
            <x-ui.card title="Most exposed repositories" :description="$withComposer->count().' of '.$this->repositories->count().' repositories have a composer.lock.'">
                @php($max = max(array_column($this->exposure, 'total')))
                <ul class="grid gap-3">
                    @foreach ($this->exposure as $row)
                        <li class="grid gap-1.5 sm:grid-cols-[14rem_1fr_2rem] sm:items-center sm:gap-4">
                            <a href="{{ route('repositories.show', ['repository' => $row['repository'], 'tab' => 'security']) }}" wire:navigate class="truncate text-sm font-medium hover:underline">{{ $row['repository']->full_name }}</a>
                            <div class="flex h-2.5 overflow-hidden rounded-full bg-muted" style="width: {{ max(4, round($row['total'] / $max * 100)) }}%">
                                @foreach ($bars as $value => $color)
                                    @if ($row['counts'][$value] ?? 0)
                                        <span class="{{ $color }} h-full" style="flex: {{ $row['counts'][$value] }}" title="{{ $row['counts'][$value] }} {{ $value ?: 'unrated' }}"></span>
                                    @endif
                                @endforeach
                            </div>
                            <span class="text-right text-sm tabular-nums text-muted-foreground">{{ $row['total'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        <x-ui.card>
            <div class="grid gap-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="relative w-full max-w-sm">
                        <x-ui.icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground" />
                        <x-ui.input wire:model.live.debounce.300ms="search" placeholder="Package, title or CVE…" class="pl-9" />
                    </div>
                    @if ($repositoryId && $this->repositories->first()?->composer_packages_count !== null)
                        <span class="text-sm text-muted-foreground">{{ $this->repositories->first()->composer_packages_count }} packages checked at <code>{{ substr((string) $this->repositories->first()->dependencies_commit_sha, 0, 7) }}</code></span>
                    @endif
                </div>

                @if ($this->advisories->isEmpty())
                    <div class="flex flex-col items-center gap-2 py-10 text-center">
                        <x-ui.icon name="check" class="size-8 text-success" />
                        <p class="font-medium">{{ $total === 0 ? 'No known vulnerabilities' : 'Nothing matches these filters' }}</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-muted-foreground">
                                    <th class="h-10 px-2 font-medium">Severity</th>
                                    <th class="h-10 px-2 font-medium">Package</th>
                                    <th class="h-10 px-2 font-medium">Advisory</th>
                                    @unless ($repositoryId)
                                        <th class="h-10 px-2 font-medium">Repository</th>
                                    @endunless
                                    <th class="h-10 px-2 font-medium">Reported</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->advisories as $advisory)
                                    <tr wire:key="advisory-{{ $advisory->id }}" class="border-b align-top transition-colors last:border-0 hover:bg-muted/50">
                                        <td class="p-2">
                                            @if ($advisory->severity)
                                                <x-ui.badge :variant="$advisory->severity->badgeVariant()">{{ $advisory->severity->label() }}</x-ui.badge>
                                            @else
                                                <x-ui.badge variant="outline">Unrated</x-ui.badge>
                                            @endif
                                        </td>
                                        <td class="p-2">
                                            <div class="grid gap-0.5">
                                                <span class="font-mono text-xs font-medium">{{ $advisory->package }}</span>
                                                <span class="flex items-center gap-1.5 text-xs text-muted-foreground">
                                                    {{ $advisory->installed_version }}
                                                    @if ($advisory->is_dev)
                                                        <x-ui.badge variant="secondary">dev</x-ui.badge>
                                                    @endif
                                                </span>
                                            </div>
                                        </td>
                                        <td class="p-2">
                                            <div class="grid gap-0.5">
                                                @if ($advisory->link)
                                                    <a href="{{ $advisory->link }}" target="_blank" rel="noopener" class="font-medium hover:underline">{{ $advisory->title }}</a>
                                                @else
                                                    <span class="font-medium">{{ $advisory->title }}</span>
                                                @endif
                                                <span class="text-xs text-muted-foreground">
                                                    {{ $advisory->cve ?? $advisory->advisory_id }} · affects <code>{{ $advisory->affected_versions }}</code>
                                                </span>
                                            </div>
                                        </td>
                                        @unless ($repositoryId)
                                            <td class="p-2">
                                                <a href="{{ route('repositories.show', ['repository' => $advisory->repository, 'tab' => 'security']) }}" wire:navigate class="hover:underline">{{ $advisory->repository->full_name }}</a>
                                            </td>
                                        @endunless
                                        <td class="p-2 whitespace-nowrap text-muted-foreground">{{ $advisory->reported_at?->toFormattedDateString() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{ $this->advisories->links() }}
                @endif
            </div>
        </x-ui.card>
    @endif
</div>
