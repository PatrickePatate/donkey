<?php

use App\Actions\StartReview;
use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\ReviewTrigger;
use App\Models\Organization;
use App\Models\PullRequest;
use App\Models\ReviewAgent;
use App\Models\ReviewRun;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public ?int $agentId = null;

    public string $name = '';

    public string $slug = '';

    public string $description = '';

    public string $instructions = '';

    public string $model = '';

    public string $repositoryId = '';

    public string $fileGlobs = '';

    /** @var list<string> */
    public array $categories = [];

    public string $severityFloor = '';

    public bool $runOnAutoReview = false;

    public bool $enabled = true;

    public string $testPullRequestId = '';

    public function mount(?ReviewAgent $agent = null): void
    {
        if ($agent?->exists) {
            Gate::authorize('view', $agent);
            abort_unless($agent->organization_id === $this->organization->id, 404);

            $this->agentId = $agent->id;
            $this->fill([
                'name' => $agent->name,
                'slug' => $agent->slug,
                'description' => (string) $agent->description,
                'instructions' => $agent->instructions,
                'model' => (string) $agent->model,
                'repositoryId' => (string) $agent->repository_id,
                'fileGlobs' => implode("\n", $agent->file_globs ?? []),
                'categories' => $agent->categories ?? [],
                'severityFloor' => (string) $agent->severity_floor?->value,
                'runOnAutoReview' => $agent->run_on_auto_review,
                'enabled' => $agent->enabled,
            ]);

            return;
        }

        Gate::authorize('create', [ReviewAgent::class, $this->organization]);

        $template = config('agents.templates.'.request()->query('template'));

        if (is_array($template)) {
            $this->fill([
                'name' => $template['name'],
                'slug' => Str::slug($template['name']),
                'description' => $template['description'],
                'instructions' => $template['instructions'],
                'fileGlobs' => implode("\n", $template['file_globs']),
                'categories' => $template['categories'],
                'severityFloor' => (string) $template['severity_floor'],
            ]);
        }
    }

    public function rendering($view): void
    {
        $view->title($this->agentId !== null ? "Agent · {$this->name}" : 'New agent');
    }

    #[Computed]
    public function organization(): Organization
    {
        return auth()->user()->currentOrganization;
    }

    #[Computed]
    public function agent(): ?ReviewAgent
    {
        return $this->agentId !== null ? $this->organization->reviewAgents()->find($this->agentId) : null;
    }

    /**
     * @return Collection<int, App\Models\Repository>
     */
    #[Computed]
    public function repositories(): Collection
    {
        return $this->organization->repositories()->orderBy('full_name')->get(['id', 'full_name']);
    }

    /**
     * Open pull requests the agent could be tried on.
     *
     * @return Collection<int, PullRequest>
     */
    #[Computed]
    public function testablePullRequests(): Collection
    {
        return PullRequest::query()
            ->whereIn('repository_id', $this->repositoryId !== '' ? [(int) $this->repositoryId] : $this->repositories->modelKeys())
            ->where('state', 'open')
            ->with('repository')
            ->latest('updated_at')
            ->limit(50)
            ->get();
    }

    /**
     * @return Collection<int, ReviewRun>
     */
    #[Computed]
    public function testRuns(): Collection
    {
        if ($this->agentId === null) {
            return new Collection;
        }

        return ReviewRun::query()
            ->where('review_agent_id', $this->agentId)
            ->with('pullRequest.repository')
            ->withCount(['findings as published_count' => fn ($query) => $query->where('status', 'published')])
            ->latest('id')
            ->limit(5)
            ->get();
    }

    public function updatedName(string $value): void
    {
        if ($this->agentId === null && $this->slug === '') {
            $this->slug = Str::slug($value);
        }
    }

    public function save(): void
    {
        $this->agent !== null
            ? Gate::authorize('update', $this->agent)
            : Gate::authorize('create', [ReviewAgent::class, $this->organization]);

        $this->slug = Str::lower(trim($this->slug));

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'slug' => [
                'required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/',
                Rule::unique('review_agents', 'slug')
                    ->where('organization_id', $this->organization->id)
                    ->where(fn ($query) => $this->repositoryId === '' ? $query->whereNull('repository_id') : $query->where('repository_id', (int) $this->repositoryId))
                    ->ignore($this->agentId),
            ],
            'description' => ['nullable', 'string', 'max:160'],
            'instructions' => ['required', 'string', 'min:20', 'max:8000'],
            'model' => ['nullable', 'string', 'max:100'],
            'repositoryId' => ['nullable', Rule::exists('repositories', 'id')->where('organization_id', $this->organization->id)],
            'fileGlobs' => ['nullable', 'string', 'max:2000'],
            'categories' => ['array'],
            'categories.*' => [Rule::enum(FindingCategory::class)],
            'severityFloor' => ['nullable', Rule::enum(FindingSeverity::class)],
            'runOnAutoReview' => ['boolean'],
            'enabled' => ['boolean'],
        ], [
            'slug.regex' => 'Use lowercase letters, numbers and dashes, starting with a letter or number.',
        ]);

        $attributes = [
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => filled($validated['description']) ? $validated['description'] : null,
            'instructions' => trim($validated['instructions']),
            'model' => filled($validated['model']) ? trim($validated['model']) : null,
            'repository_id' => filled($validated['repositoryId']) ? (int) $validated['repositoryId'] : null,
            'file_globs' => $this->parsedGlobs(),
            'categories' => array_values($validated['categories']) ?: null,
            'severity_floor' => filled($validated['severityFloor']) ? $validated['severityFloor'] : null,
            'run_on_auto_review' => $validated['runOnAutoReview'],
            'enabled' => $validated['enabled'],
        ];

        if ($this->agent !== null) {
            $this->agent->update($attributes);
            $this->dispatch('toast', message: 'Agent saved.', variant: 'success');

            return;
        }

        $agent = $this->organization->reviewAgents()->create($attributes);

        session()->flash('success', "{$agent->name} agent created.");
        $this->redirectRoute('agents.edit', $agent, navigate: true);
    }

    public function delete(): void
    {
        Gate::authorize('delete', $this->agent);

        $name = $this->agent->name;
        $this->agent->delete();

        session()->flash('success', "{$name} agent deleted.");
        $this->redirectRoute('agents.index', navigate: true);
    }

    /**
     * Run the saved agent on a pull request without posting anything to GitHub.
     */
    public function test(StartReview $startReview): void
    {
        Gate::authorize('test', $this->agent);
        $this->resetErrorBag('testPullRequestId');

        $pullRequest = $this->testablePullRequests->firstWhere('id', (int) $this->testPullRequestId);

        if ($pullRequest === null) {
            $this->addError('testPullRequestId', 'Choose an open pull request.');

            return;
        }

        $run = $startReview->handle(
            $pullRequest,
            ReviewTrigger::Manual,
            auth()->user()->name,
            source: (string) Str::uuid(),
            agent: $this->agent,
            dryRun: true,
        );

        unset($this->testRuns);

        $this->dispatch('toast', message: $run !== null ? 'Test run queued. Nothing will be posted on GitHub.' : 'A test run is already queued.', variant: 'success');
    }

    /**
     * @return ?list<string>
     */
    private function parsedGlobs(): ?array
    {
        $globs = collect(preg_split('/[\r\n,]+/', $this->fileGlobs) ?: [])
            ->map(fn (string $glob): string => trim($glob))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $globs === [] ? null : $globs;
    }
};
?>

<div class="grid max-w-3xl gap-6">
    <div class="grid gap-1">
        <a href="{{ route('agents.index') }}" wire:navigate class="text-sm text-muted-foreground hover:text-foreground">← Agents</a>
        <h1 class="text-2xl font-semibold tracking-tight">{{ $this->agent !== null ? $this->agent->name : 'New agent' }}</h1>
        <p class="text-sm text-muted-foreground">
            An agent reviews the same diff as Donkey, with your instructions, and only reports findings within its focus.
            Its findings go through the same verifier and risk gate.
        </p>
    </div>

    @php($canEdit = $this->agent !== null ? auth()->user()->can('update', $this->agent) : true)

    <form wire:submit="save">
        <fieldset @disabled(! $canEdit) class="grid gap-6">
            <x-ui.card title="Identity">
                <div class="grid gap-6">
                    <div class="grid gap-6 sm:grid-cols-2">
                        <x-ui.field label="Name" for="name" error="name">
                            <x-ui.input id="name" wire:model.blur="name" required maxlength="60" />
                        </x-ui.field>

                        <x-ui.field label="Slug" for="slug" error="slug" :hint="'Used in @'.$this->organization->bot_handle.' run '.($slug ?: 'slug').'.'">
                            <x-ui.input id="slug" wire:model.blur="slug" required maxlength="40" class="font-mono" />
                        </x-ui.field>
                    </div>

                    <x-ui.field label="Description" for="description" error="description" hint="Shown when someone lists agents on a pull request.">
                        <x-ui.input id="description" wire:model="description" maxlength="160" />
                    </x-ui.field>

                    <x-ui.field label="Scope" for="repositoryId" error="repositoryId" hint="A repository agent replaces an organization agent with the same slug in that repository.">
                        <x-ui.select id="repositoryId" wire:model.live="repositoryId">
                            <option value="">Every repository in {{ $this->organization->name }}</option>
                            @foreach ($this->repositories as $repository)
                                <option value="{{ $repository->id }}">{{ $repository->full_name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                </div>
            </x-ui.card>

            <x-ui.card title="Instructions" description="What the agent looks for. Write it like a brief to a specialist reviewer.">
                <x-ui.field for="instructions" error="instructions">
                    <x-ui.textarea id="instructions" wire:model="instructions" rows="12" class="min-h-48 font-mono text-xs" required />
                </x-ui.field>
            </x-ui.card>

            <x-ui.card title="Focus">
                <div class="grid gap-6">
                    <x-ui.field label="Files" for="fileGlobs" error="fileGlobs" hint="One glob per line, e.g. app/Http/* or *.php. Leave empty for every file.">
                        <x-ui.textarea id="fileGlobs" wire:model="fileGlobs" rows="3" class="font-mono text-xs" placeholder="app/Http/*&#10;*.php" />
                    </x-ui.field>

                    <x-ui.field label="Categories" error="categories" hint="Findings in other categories are dropped. None checked means all.">
                        <div class="flex flex-wrap gap-x-5 gap-y-2">
                            @foreach (App\Enums\FindingCategory::cases() as $category)
                                <x-ui.label class="font-normal">
                                    <x-ui.checkbox wire:model="categories" value="{{ $category->value }}" />
                                    {{ $category->label() }}
                                </x-ui.label>
                            @endforeach
                        </div>
                    </x-ui.field>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <x-ui.field label="Minimum severity" for="severityFloor" error="severityFloor">
                            <x-ui.select id="severityFloor" wire:model="severityFloor">
                                <option value="">Any severity</option>
                                @foreach (App\Enums\FindingSeverity::cases() as $severity)
                                    <option value="{{ $severity->value }}">{{ $severity->label() }} and above</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field label="Model" for="model" error="model" :hint="'Leave empty to use '.config('reviews.model').'.'">
                            <x-ui.input id="model" wire:model="model" class="font-mono" :placeholder="config('reviews.model')" />
                        </x-ui.field>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card title="Runs">
                <div class="grid gap-6">
                    <div class="flex items-center justify-between gap-4">
                        <div class="grid gap-1">
                            <x-ui.label>Join automatic reviews</x-ui.label>
                            <p class="text-sm text-muted-foreground">Also run on every automatic review when matching files change. Each run costs an extra model call.</p>
                        </div>
                        <x-ui.switch wire:model="runOnAutoReview" :checked="$runOnAutoReview" :disabled="! $canEdit" />
                    </div>

                    <x-ui.separator />

                    <div class="flex items-center justify-between gap-4">
                        <div class="grid gap-1">
                            <x-ui.label>Enabled</x-ui.label>
                            <p class="text-sm text-muted-foreground">Disabled agents can't be run and don't join reviews.</p>
                        </div>
                        <x-ui.switch wire:model="enabled" :checked="$enabled" :disabled="! $canEdit" />
                    </div>
                </div>

                @if ($canEdit)
                    <x-slot:footer>
                        <div class="flex w-full items-center justify-between gap-2">
                            <x-ui.button type="submit">{{ $this->agent !== null ? 'Save changes' : 'Create agent' }}</x-ui.button>
                            @if ($this->agent !== null)
                                <x-ui.button type="button" variant="ghost" class="text-destructive" x-on:click="$dispatch('open-dialog', 'delete-agent')">
                                    <x-ui.icon name="trash" /> Delete
                                </x-ui.button>
                            @endif
                        </div>
                    </x-slot:footer>
                @endif
            </x-ui.card>
        </fieldset>
    </form>

    @if ($this->agent !== null)
        @can('test', $this->agent)
            <x-ui.card title="Try it" description="Run the saved agent on an open pull request. Findings show up on the pull request page; nothing is posted on GitHub.">
                <div class="grid gap-4">
                    <form wire:submit="test" class="flex flex-wrap items-start gap-2">
                        <x-ui.field error="testPullRequestId" class="min-w-64 flex-1">
                            <x-ui.select wire:model="testPullRequestId" aria-label="Pull request">
                                <option value="">Choose an open pull request…</option>
                                @foreach ($this->testablePullRequests as $pullRequest)
                                    <option value="{{ $pullRequest->id }}">{{ $pullRequest->repository->full_name }} #{{ $pullRequest->number }} · {{ Str::limit($pullRequest->title, 60) }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                        <x-ui.button type="submit" variant="outline" :disabled="$this->testablePullRequests->isEmpty()">
                            <x-ui.icon name="flask" /> Test run
                        </x-ui.button>
                    </form>

                    @if ($this->testRuns->isNotEmpty())
                        <div class="grid gap-1">
                            <p class="text-sm font-medium">Recent runs</p>
                            <ul class="divide-y rounded-md border text-sm">
                                @foreach ($this->testRuns as $run)
                                    <li wire:key="test-run-{{ $run->id }}">
                                        <a href="{{ route('pull-requests.show', ['pullRequest' => $run->pull_request_id, 'run' => $run->id]) }}" wire:navigate class="flex flex-wrap items-center gap-2 px-3 py-2 hover:bg-muted/50">
                                            <x-ui.badge :variant="$run->status->badgeVariant()">{{ $run->status->label() }}</x-ui.badge>
                                            @if ($run->is_dry_run)
                                                <x-ui.badge variant="outline">Test</x-ui.badge>
                                            @endif
                                            <span class="min-w-0 flex-1 truncate">{{ $run->pullRequest->repository->full_name }} #{{ $run->pullRequest->number }}</span>
                                            <span class="text-muted-foreground">{{ $run->published_count }} {{ Str::plural('finding', $run->published_count) }} · {{ $run->created_at->diffForHumans() }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </x-ui.card>
        @endcan

        <x-ui.dialog name="delete-agent" title="Delete this agent?" description="It will no longer run on pull requests. Findings it already posted stay on GitHub.">
            <div class="flex justify-end gap-2">
                <x-ui.button variant="outline" x-on:click="$dispatch('close-dialog')">Cancel</x-ui.button>
                <x-ui.button variant="destructive" wire:click="delete">Delete agent</x-ui.button>
            </div>
        </x-ui.dialog>
    @endif
</div>
