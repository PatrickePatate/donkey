<?php

use App\Enums\FindingCategory;
use App\Enums\LearningKind;
use App\Enums\LearningStatus;
use App\Models\Learning;
use App\Models\Organization;
use App\Models\Repository;
use App\Models\RepositoryMemory;
use App\Models\ReviewFinding;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Knowledge')] class extends Component
{
    use WithPagination;

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function updatedRepositoryFilter(): void
    {
        $this->resetPage();
    }

    #[Url]
    public string $tab = 'inbox';

    #[Url(as: 'repository')]
    public string $repositoryFilter = '';

    public ?int $editingLearningId = null;

    public string $learningBody = '';

    public string $learningKind = 'false_positive';

    public string $learningRepositoryId = '';

    public string $learningCategory = '';

    public string $learningPathGlob = '';

    public string $memoryRepositoryId = '';

    public string $memoryBody = '';

    public string $memoryPathGlob = '';

    public bool $memoryPinned = false;

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
        return $this->organization->repositories()->orderBy('full_name')->get(['id', 'full_name', 'organization_id']);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        $byStatus = $this->organization->learnings()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'inbox' => (int) ($byStatus[LearningStatus::Proposed->value] ?? 0),
            'learnings' => (int) ($byStatus[LearningStatus::Active->value] ?? 0) + (int) ($byStatus[LearningStatus::Archived->value] ?? 0),
            'memories' => RepositoryMemory::query()->whereIn('repository_id', $this->repositories->modelKeys())->count(),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Learning>
     */
    #[Computed]
    public function learnings(): LengthAwarePaginator
    {
        $statuses = $this->tab === 'inbox' ? [LearningStatus::Proposed] : [LearningStatus::Active, LearningStatus::Archived];

        return $this->organization->learnings()
            ->with('repository', 'reviewer')
            ->whereIn('status', $statuses)
            ->when($this->repositoryFilter !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('repository_id', (int) $this->repositoryFilter)
                ->orWhereNull('repository_id')))
            ->orderByRaw("case status when 'active' then 0 else 1 end")
            ->orderByDesc('weight')
            ->latest('id')
            ->paginate(25);
    }

    /**
     * Findings each learning was built from, keyed by id.
     *
     * @return Collection<int, ReviewFinding>
     */
    #[Computed]
    public function sourceFindings(): Collection
    {
        $ids = $this->learnings->pluck('source_finding_ids')->flatten()->filter()->unique()->values()->all();

        return ReviewFinding::query()->whereKey($ids)->with('pullRequest')->get()->keyBy('id');
    }

    /**
     * @return LengthAwarePaginator<int, RepositoryMemory>
     */
    #[Computed]
    public function memories(): LengthAwarePaginator
    {
        return RepositoryMemory::query()
            ->whereIn('repository_id', $this->repositoryFilter !== '' ? [(int) $this->repositoryFilter] : $this->repositories->modelKeys())
            ->with('repository')
            ->orderByDesc('pinned')
            ->latest('id')
            ->paginate(25);
    }

    public function approve(int $learningId): void
    {
        $learning = $this->findLearning($learningId);
        Gate::authorize('update', $learning);

        $learning->update([
            'status' => LearningStatus::Active,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'weight' => max(1, $learning->weight),
        ]);

        app(\App\Services\Audit\AuditLog::class)->record($this->organization, 'learning.approved', $learning, ['body' => $learning->body]);
        $this->refreshLearnings();
        $this->dispatch('toast', message: 'Learning approved. Reviews use it from now on.', variant: 'success');
    }

    public function reject(int $learningId): void
    {
        $learning = $this->findLearning($learningId);
        Gate::authorize('update', $learning);

        $learning->update(['status' => LearningStatus::Rejected, 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);

        app(\App\Services\Audit\AuditLog::class)->record($this->organization, 'learning.rejected', $learning, ['body' => $learning->body]);
        $this->refreshLearnings();
        $this->dispatch('toast', message: 'Learning rejected. It will not be proposed again.', variant: 'success');
    }

    public function archive(int $learningId): void
    {
        $learning = $this->findLearning($learningId);
        Gate::authorize('update', $learning);

        $learning->update(['status' => $learning->status === LearningStatus::Archived ? LearningStatus::Active : LearningStatus::Archived, 'weight' => max(1, $learning->weight)]);
        app(\App\Services\Audit\AuditLog::class)->record($this->organization, $learning->status === LearningStatus::Archived ? 'learning.archived' : 'learning.reactivated', $learning);

        $this->refreshLearnings();
    }

    public function editLearning(int $learningId): void
    {
        $learning = $this->findLearning($learningId);
        Gate::authorize('update', $learning);

        $this->resetValidation();
        $this->editingLearningId = $learning->id;
        $this->learningBody = $learning->body;
        $this->learningKind = $learning->kind->value;
        $this->learningRepositoryId = (string) $learning->repository_id;
        $this->learningCategory = (string) $learning->category?->value;
        $this->learningPathGlob = (string) $learning->path_glob;

        $this->dispatch('open-dialog', 'edit-learning');
    }

    public function saveLearning(): void
    {
        $learning = $this->findLearning((int) $this->editingLearningId);
        Gate::authorize('update', $learning);

        $validated = $this->validate([
            'learningBody' => ['required', 'string', 'min:10', 'max:1000'],
            'learningKind' => ['required', Rule::enum(LearningKind::class)],
            'learningRepositoryId' => ['nullable', Rule::in($this->repositories->modelKeys())],
            'learningCategory' => ['nullable', Rule::enum(FindingCategory::class)],
            'learningPathGlob' => ['nullable', 'string', 'max:250'],
        ]);

        $learning->update([
            'body' => trim($validated['learningBody']),
            'kind' => $validated['learningKind'],
            'repository_id' => filled($validated['learningRepositoryId']) ? (int) $validated['learningRepositoryId'] : null,
            'category' => filled($validated['learningCategory']) ? $validated['learningCategory'] : null,
            'path_glob' => filled($validated['learningPathGlob']) ? trim($validated['learningPathGlob']) : null,
        ]);

        app(\App\Services\Audit\AuditLog::class)->record($this->organization, 'learning.updated', $learning, App\Services\Audit\AuditLog::changes($learning));
        $this->editingLearningId = null;
        $this->refreshLearnings();
        $this->dispatch('close-dialog');
        $this->dispatch('toast', message: 'Learning saved.', variant: 'success');
    }

    public function addMemory(): void
    {
        $validated = $this->validate([
            'memoryRepositoryId' => ['required', Rule::in($this->repositories->modelKeys())],
            'memoryBody' => ['required', 'string', 'min:5', 'max:2000'],
            'memoryPathGlob' => ['nullable', 'string', 'max:250'],
            'memoryPinned' => ['boolean'],
        ], attributes: ['memoryRepositoryId' => 'repository', 'memoryBody' => 'memory']);

        $repository = $this->repositories->firstWhere('id', (int) $validated['memoryRepositoryId']);
        Gate::authorize('create', [RepositoryMemory::class, $repository]);

        $repository->memories()->create([
            'user_id' => auth()->id(),
            'author_login' => auth()->user()->name,
            'body' => trim($validated['memoryBody']),
            'path_glob' => filled($validated['memoryPathGlob']) ? trim($validated['memoryPathGlob']) : null,
            'pinned' => $validated['memoryPinned'],
            'source' => RepositoryMemory::SOURCE_DASHBOARD,
        ]);

        $this->reset('memoryBody', 'memoryPathGlob', 'memoryPinned');
        unset($this->memories, $this->counts);
        $this->dispatch('toast', message: 'Memory saved.', variant: 'success');
    }

    public function togglePin(int $memoryId): void
    {
        $memory = $this->findMemory($memoryId);
        Gate::authorize('update', $memory);

        $memory->update(['pinned' => !$memory->pinned]);
        unset($this->memories);
    }

    public function deleteMemory(int $memoryId): void
    {
        $memory = $this->findMemory($memoryId);
        Gate::authorize('delete', $memory);

        app(\App\Services\Audit\AuditLog::class)->record($this->organization, 'memory.deleted', metadata: ['repository' => $memory->repository->full_name, 'body' => $memory->body]);
        $memory->delete();
        unset($this->memories, $this->counts);
        $this->dispatch('toast', message: 'Memory deleted.', variant: 'success');
    }

    private function findLearning(int $learningId): Learning
    {
        return $this->organization->learnings()->findOrFail($learningId);
    }

    private function findMemory(int $memoryId): RepositoryMemory
    {
        return RepositoryMemory::query()->whereIn('repository_id', $this->repositories->modelKeys())->findOrFail($memoryId);
    }

    private function refreshLearnings(): void
    {
        unset($this->learnings, $this->counts, $this->sourceFindings);
    }
};
?>

<div class="grid gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="grid gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">Knowledge</h1>
            <p class="max-w-2xl text-sm text-muted-foreground">
                What Donkey knows about your code. Memories come from your team
                (<code class="rounded bg-muted px-1.5 py-0.5 text-xs">{{ '@'.$this->organization->bot_handle }} remember …</code>);
                learnings are proposed from feedback on its findings and used once an admin approves them.
            </p>
        </div>

        <div class="w-64">
            <x-ui.select wire:model.live="repositoryFilter" aria-label="Repository">
                <option value="">All repositories</option>
                @foreach ($this->repositories as $repository)
                    <option value="{{ $repository->id }}">{{ $repository->full_name }}</option>
                @endforeach
            </x-ui.select>
        </div>
    </div>

    <div class="inline-flex w-fit rounded-md border p-0.5 text-sm">
        @foreach (['inbox' => 'Inbox', 'learnings' => 'Learnings', 'memories' => 'Memories'] as $key => $label)
            <button
                type="button"
                wire:click="$set('tab', '{{ $key }}')"
                @class([
                    'rounded px-3 py-1',
                    'bg-secondary font-medium' => $tab === $key,
                    'text-muted-foreground hover:text-foreground' => $tab !== $key,
                ])
            >{{ $label }} <span class="text-muted-foreground tabular-nums">{{ $this->counts[$key] }}</span></button>
        @endforeach
    </div>

    @if ($tab === 'memories')
        @if ($this->repositories->isNotEmpty() && auth()->user()->can('create', [App\Models\RepositoryMemory::class, $this->repositories->first()]))
            <x-ui.card title="Add a memory" description="Facts and conventions the reviewer should know. Pinned memories are always included; others when their files change.">
                <form wire:submit="addMemory" class="grid gap-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.field label="Repository" for="memoryRepositoryId" error="memoryRepositoryId">
                            <x-ui.select id="memoryRepositoryId" wire:model="memoryRepositoryId">
                                <option value="">Choose…</option>
                                @foreach ($this->repositories as $repository)
                                    <option value="{{ $repository->id }}">{{ $repository->full_name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                        <x-ui.field label="Files" for="memoryPathGlob" error="memoryPathGlob" hint="Optional glob, e.g. app/Billing/*">
                            <x-ui.input id="memoryPathGlob" wire:model="memoryPathGlob" class="font-mono" />
                        </x-ui.field>
                    </div>
                    <x-ui.field label="Memory" for="memoryBody" error="memoryBody">
                        <x-ui.textarea id="memoryBody" wire:model="memoryBody" rows="3" placeholder="Money amounts are integers in cents; never use floats." />
                    </x-ui.field>
                    <div class="flex items-center justify-between gap-4">
                        <x-ui.label class="font-normal"><x-ui.checkbox wire:model="memoryPinned" /> Pin: include in every review of this repository</x-ui.label>
                        <x-ui.button type="submit">Save memory</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        <x-ui.card>
            @forelse ($this->memories as $memory)
                <div wire:key="memory-{{ $memory->id }}" class="flex items-start gap-3 border-b py-3 first:pt-0 last:border-0 last:pb-0">
                    <div class="grid min-w-0 flex-1 gap-1">
                        <p class="text-sm whitespace-pre-line">{{ $memory->body }}</p>
                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                            <span>{{ $memory->repository->full_name }}</span>
                            @if ($memory->path_glob)
                                <code class="rounded bg-muted px-1">{{ $memory->path_glob }}</code>
                            @endif
                            <span>· {{ '@'.$memory->author_login }} via {{ $memory->source === 'github' ? 'GitHub' : 'dashboard' }}</span>
                            <span>· used in {{ $memory->times_used }} {{ str('review')->plural($memory->times_used) }}</span>
                        </p>
                    </div>
                    @if ($memory->pinned)
                        <x-ui.badge variant="secondary"><x-ui.icon name="pin" /> Pinned</x-ui.badge>
                    @endif
                    @can('update', $memory)
                        <x-ui.button variant="ghost" size="icon" wire:click="togglePin({{ $memory->id }})" :title="$memory->pinned ? 'Unpin' : 'Pin'">
                            <x-ui.icon name="pin" />
                        </x-ui.button>
                        <x-ui.button variant="ghost" size="icon" wire:click="deleteMemory({{ $memory->id }})" wire:confirm="Delete this memory?" title="Delete">
                            <x-ui.icon name="trash" />
                        </x-ui.button>
                    @endcan
                </div>
            @empty
                <div class="flex flex-col items-center gap-2 py-10 text-center">
                    <x-ui.icon name="lightbulb" class="size-8 text-muted-foreground" />
                    <p class="font-medium">No memories yet</p>
                    <p class="max-w-sm text-sm text-muted-foreground">Add one here, or comment <code>{{ '@'.$this->organization->bot_handle }} remember …</code> on a pull request.</p>
                </div>
            @endforelse

            {{ $this->memories->links() }}
        </x-ui.card>
    @else
        <div class="grid gap-3">
            @forelse ($this->learnings as $learning)
                <x-ui.card wire:key="learning-{{ $learning->id }}">
                    <div class="grid gap-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge :variant="$learning->status->badgeVariant()">{{ $learning->status->label() }}</x-ui.badge>
                            <x-ui.badge variant="outline">{{ $learning->kind->label() }}</x-ui.badge>
                            <x-ui.badge variant="secondary">{{ $learning->scopeLabel() }}</x-ui.badge>
                            @if ($learning->category)
                                <x-ui.badge variant="outline">{{ $learning->category->label() }}</x-ui.badge>
                            @endif
                            @if ($learning->path_glob)
                                <code class="rounded bg-muted px-1.5 py-0.5 text-xs">{{ $learning->path_glob }}</code>
                            @endif
                            @if ($learning->status !== LearningStatus::Proposed)
                                <span class="ml-auto text-xs text-muted-foreground tabular-nums">
                                    Matched {{ $learning->times_applied }} {{ str('time')->plural($learning->times_applied) }} · weight {{ number_format($learning->weight, 2) }}
                                </span>
                            @endif
                        </div>

                        <p class="text-sm font-medium">{{ $learning->body }}</p>

                        @if ($learning->rationale)
                            <p class="text-sm text-muted-foreground">{{ $learning->rationale }}</p>
                        @endif

                        @php($sources = collect($learning->source_finding_ids ?? [])->map(fn ($id) => $this->sourceFindings[$id] ?? null)->filter())
                        @if ($sources->isNotEmpty())
                            <details class="text-sm">
                                <summary class="cursor-pointer text-muted-foreground">Based on {{ $sources->count() }} {{ str('finding')->plural($sources->count()) }}</summary>
                                <ul class="mt-2 grid gap-1">
                                    @foreach ($sources as $finding)
                                        <li>
                                            <a href="{{ route('pull-requests.show', ['pullRequest' => $finding->pull_request_id, 'run' => $finding->review_run_id, 'findingStatus' => 'all']) }}" wire:navigate class="hover:underline">
                                                #{{ $finding->pullRequest->number }} · {{ $finding->title }}
                                            </a>
                                            <span class="text-xs text-muted-foreground">({{ $finding->file_path }})</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif

                        @can('update', $learning)
                            <div class="flex flex-wrap gap-2">
                                @if ($learning->status === LearningStatus::Proposed)
                                    <x-ui.button size="sm" wire:click="approve({{ $learning->id }})"><x-ui.icon name="check" /> Approve</x-ui.button>
                                    <x-ui.button size="sm" variant="outline" wire:click="reject({{ $learning->id }})"><x-ui.icon name="x" /> Reject</x-ui.button>
                                @else
                                    <x-ui.button size="sm" variant="outline" wire:click="archive({{ $learning->id }})">
                                        <x-ui.icon name="archive" /> {{ $learning->status === LearningStatus::Archived ? 'Reactivate' : 'Archive' }}
                                    </x-ui.button>
                                @endif
                                <x-ui.button size="sm" variant="ghost" wire:click="editLearning({{ $learning->id }})"><x-ui.icon name="pencil" /> Edit</x-ui.button>
                            </div>
                        @elseif ($learning->reviewer)
                            <p class="text-xs text-muted-foreground">Approved by {{ $learning->reviewer->name }} {{ $learning->reviewed_at?->diffForHumans() }}</p>
                        @endcan
                    </div>
                </x-ui.card>
            @empty
                <x-ui.card>
                    <div class="flex flex-col items-center gap-2 py-10 text-center">
                        <x-ui.icon name="lightbulb" class="size-8 text-muted-foreground" />
                        <p class="font-medium">{{ $tab === 'inbox' ? 'Nothing to review' : 'No learnings yet' }}</p>
                        <p class="max-w-md text-sm text-muted-foreground">
                            When people dismiss findings (<code>{{ '@'.$this->organization->bot_handle }} ignore: why</code>), react 👎, or merge without acting on them,
                            Donkey proposes general learnings here for an admin to approve.
                        </p>
                    </div>
                </x-ui.card>
            @endforelse

            {{ $this->learnings->links() }}
        </div>
    @endif

    <x-ui.dialog name="edit-learning" title="Edit learning" description="Write it as a rule a reviewer can apply to future code.">
        <form wire:submit="saveLearning" class="grid gap-4">
            <x-ui.field label="Learning" for="learningBody" error="learningBody">
                <x-ui.textarea id="learningBody" wire:model="learningBody" rows="4" />
            </x-ui.field>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Kind" for="learningKind" error="learningKind">
                    <x-ui.select id="learningKind" wire:model="learningKind">
                        @foreach (LearningKind::cases() as $kind)
                            <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="Scope" for="learningRepositoryId" error="learningRepositoryId">
                    <x-ui.select id="learningRepositoryId" wire:model="learningRepositoryId">
                        <option value="">Whole organization</option>
                        @foreach ($this->repositories as $repository)
                            <option value="{{ $repository->id }}">{{ $repository->full_name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="Category" for="learningCategory" error="learningCategory">
                    <x-ui.select id="learningCategory" wire:model="learningCategory">
                        <option value="">Any</option>
                        @foreach (FindingCategory::cases() as $category)
                            <option value="{{ $category->value }}">{{ $category->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="Files" for="learningPathGlob" error="learningPathGlob">
                    <x-ui.input id="learningPathGlob" wire:model="learningPathGlob" class="font-mono" placeholder="Every file" />
                </x-ui.field>
            </div>
            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" x-on:click="$dispatch('close-dialog')">Cancel</x-ui.button>
                <x-ui.button type="submit">Save</x-ui.button>
            </div>
        </form>
    </x-ui.dialog>
</div>
