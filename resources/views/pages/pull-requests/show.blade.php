<?php

use App\Actions\StartReview;
use App\Enums\FindingStatus;
use App\Enums\ReviewRunStatus;
use App\Enums\ReviewStage;
use App\Enums\ReviewTrigger;
use App\Models\ConversationMessage;
use App\Models\PullRequest;
use App\Models\ReviewAgent;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
use App\Models\ReviewRunLog;
use App\Services\GitHub\PullRequestClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public PullRequest $pullRequest;

    #[Url(as: 'run')]
    public ?int $selectedRunId = null;

    #[Url(except: 'published')]
    public string $findingStatus = 'published';

    public function mount(PullRequest $pullRequest): void
    {
        Gate::authorize('view', $pullRequest);

        $this->pullRequest = $pullRequest->load('repository.organization');
    }

    public function rendering($view): void
    {
        $view->title("#{$this->pullRequest->number} {$this->pullRequest->title}");
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            "echo-private:organizations.{$this->pullRequest->repository->organization_id},.review.updated" => 'onReviewUpdated',
            "echo-private:organizations.{$this->pullRequest->repository->organization_id},.conversation.updated" => 'onConversationUpdated',
        ];
    }

    /**
     * @param  array{pull_request_id: int}  $payload
     */
    public function onReviewUpdated(array $payload): void
    {
        if ($payload['pull_request_id'] !== $this->pullRequest->id) {
            $this->skipRender();

            return;
        }

        $this->pullRequest->refresh();
        unset($this->runs, $this->selectedRun, $this->logs, $this->findings, $this->findingCounts);
    }

    /**
     * @param  array{pull_request_id: int}  $payload
     */
    public function onConversationUpdated(array $payload): void
    {
        if ($payload['pull_request_id'] !== $this->pullRequest->id) {
            $this->skipRender();

            return;
        }

        $this->pullRequest->refresh();
        unset($this->conversation, $this->findings, $this->findingCounts);
    }

    /**
     * @return Collection<int, ReviewRun>
     */
    #[Computed]
    public function runs(): Collection
    {
        return $this->pullRequest->reviewRuns()->with('agent')->latest('id')->limit(20)->get();
    }

    /**
     * @return Collection<int, ReviewAgent>
     */
    #[Computed]
    public function agents(): Collection
    {
        return ReviewAgent::availableFor($this->pullRequest->repository);
    }

    /**
     * Questions asked to the bot on GitHub and its answers, oldest first.
     *
     * @return Collection<int, ConversationMessage>
     */
    #[Computed]
    public function conversation(): Collection
    {
        return $this->pullRequest->conversationMessages()
            ->with('finding')
            ->latest('id')
            ->limit(40)
            ->get()
            ->reverse()
            ->values();
    }

    #[Computed]
    public function selectedRun(): ?ReviewRun
    {
        return $this->runs->firstWhere('id', $this->selectedRunId) ?? $this->runs->first();
    }

    /**
     * @return Collection<int, ReviewRunLog>
     */
    #[Computed]
    public function logs(): Collection
    {
        return $this->selectedRun?->logs()->orderBy('id')->limit(500)->get() ?? new Collection();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function findingCounts(): array
    {
        if ($this->selectedRun === null) {
            return [];
        }

        return $this->selectedRun->findings()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @return Collection<int, ReviewFinding>
     */
    #[Computed]
    public function findings(): Collection
    {
        if ($this->selectedRun === null) {
            return new Collection();
        }

        return $this->selectedRun->findings()
            ->with('agent', 'learning', 'signals')
            ->when($this->findingStatus !== 'all', fn ($query) => $query->where('status', $this->findingStatus))
            ->orderByDesc('risk_score')
            ->get();
    }

    public function selectRun(int $runId): void
    {
        $this->selectedRunId = $runId;
        unset($this->selectedRun, $this->logs, $this->findings, $this->findingCounts);
    }

    public function startReview(StartReview $startReview): void
    {
        Gate::authorize('review', $this->pullRequest);

        if (!$this->pullRequest->isOpen()) {
            $this->dispatch('toast', message: 'Only open pull requests can be reviewed.', variant: 'destructive');

            return;
        }

        $run = $startReview->handle($this->pullRequest, ReviewTrigger::Manual, auth()->user()->name, full: true, source: (string) Str::uuid());

        $this->selectedRunId = $run?->id;
        unset($this->runs, $this->selectedRun, $this->logs, $this->findings, $this->findingCounts);

        $this->dispatch('toast', message: 'Review queued.', variant: 'success');
    }

    public function runAgent(int $agentId, StartReview $startReview): void
    {
        Gate::authorize('review', $this->pullRequest);

        $agent = $this->agents->firstWhere('id', $agentId);

        if ($agent === null || !$this->pullRequest->isOpen()) {
            $this->dispatch('toast', message: 'This agent cannot run on this pull request.', variant: 'destructive');

            return;
        }

        $run = $startReview->handle($this->pullRequest, ReviewTrigger::Manual, auth()->user()->name, source: (string) Str::uuid(), agent: $agent);

        $this->selectedRunId = $run?->id;
        unset($this->runs, $this->selectedRun, $this->logs, $this->findings, $this->findingCounts);

        $this->dispatch('toast', message: "{$agent->name} agent queued.", variant: 'success');
    }

    public function toggleAutoReview(): void
    {
        Gate::authorize('review', $this->pullRequest);

        $this->pullRequest->update(['auto_review_paused' => !$this->pullRequest->auto_review_paused]);

        $this->dispatch('toast', message: $this->pullRequest->auto_review_paused ? 'Automatic reviews paused on this pull request.' : 'Automatic reviews resumed.', variant: 'success');
    }

    public bool $showFiles = false;

    /**
     * Files changed on GitHub, fetched only once the files panel is opened; null when GitHub failed.
     *
     * @return ?list<array<string, mixed>>
     */
    #[Computed(persist: true, seconds: 300)]
    public function files(): ?array
    {
        try {
            return app(PullRequestClient::class)->files($this->pullRequest);
        } catch (Exception $exception) {
            report($exception);

            return null;
        }
    }

    public function markdown(string $text): string
    {
        return Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }
};
?>

<div class="grid gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="grid min-w-0 gap-1">
            <a href="{{ route('pull-requests.index') }}" wire:navigate class="text-sm text-muted-foreground hover:text-foreground">← Pull requests</a>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $pullRequest->title }} <span class="font-normal text-muted-foreground">#{{ $pullRequest->number }}</span></h1>
            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
                <span>{{ $pullRequest->repository->full_name }}</span>
                <span>·</span>
                <span>{{ $pullRequest->author_login }}</span>
                <span>·</span>
                <code class="rounded bg-muted px-1.5 py-0.5 text-xs">{{ $pullRequest->head_ref }} → {{ $pullRequest->base_ref }}</code>
                <x-ui.badge :variant="$pullRequest->isOpen() ? 'success' : 'secondary'">{{ ucfirst($pullRequest->state) }}</x-ui.badge>
                @if ($pullRequest->is_draft)
                    <x-ui.badge variant="outline">Draft</x-ui.badge>
                @endif
                @if ($pullRequest->auto_review_paused)
                    <x-ui.badge variant="warning">Auto-review paused</x-ui.badge>
                @endif
            </p>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button variant="outline" :href="$pullRequest->html_url" target="_blank" rel="noopener">
                <x-ui.icon name="external-link" /> GitHub
            </x-ui.button>
            @can('review', $pullRequest)
                <x-ui.button variant="outline" wire:click="toggleAutoReview" :title="$pullRequest->auto_review_paused ? 'Review new commits automatically again' : 'Stop reviewing new commits automatically'">
                    {{ $pullRequest->auto_review_paused ? 'Resume auto-review' : 'Pause auto-review' }}
                </x-ui.button>
                @if ($this->agents->isNotEmpty())
                    <x-ui.dropdown align="end" width="w-64">
                        <x-slot:trigger>
                            <x-ui.button variant="outline" :disabled="! $pullRequest->isOpen()">
                                <x-ui.icon name="bot" /> Run agent
                            </x-ui.button>
                        </x-slot:trigger>
                        @foreach ($this->agents as $agent)
                            <x-ui.dropdown-item wire:click="runAgent({{ $agent->id }})" wire:key="run-agent-{{ $agent->id }}">
                                <span class="grid text-left">
                                    <span>{{ $agent->name }}</span>
                                    @if ($agent->description)
                                        <span class="text-xs text-muted-foreground">{{ $agent->description }}</span>
                                    @endif
                                </span>
                            </x-ui.dropdown-item>
                        @endforeach
                    </x-ui.dropdown>
                @endif
                <x-ui.button wire:click="startReview" :disabled="! $pullRequest->isOpen()">
                    <x-ui.icon name="play" /> Review now
                </x-ui.button>
            @endcan
        </div>
    </div>

    @php($run = $this->selectedRun)
    <div @class(['grid grid-cols-[minmax(0,1fr)] gap-6', 'lg:grid-cols-[minmax(0,1fr)_16rem]' => $run !== null])>
        <div class="grid min-w-0 content-start gap-6">
        @if (filled($pullRequest->body))
            <details class="group/description min-w-0" open>
                <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-foreground [&::-webkit-details-marker]:hidden">
                    <x-ui.icon name="chevron-down" class="size-4 -rotate-90 transition-transform group-open/description:rotate-0" />
                    Description
                </summary>
                <div class="prose prose-sm mt-3 max-w-none text-muted-foreground dark:prose-invert">{!! $this->markdown($pullRequest->body) !!}</div>
            </details>
        @endif

        <details class="group min-w-0" @if ($showFiles) open @endif x-on:toggle="if ($el.open) $wire.set('showFiles', true)">
            <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-md text-sm font-medium text-muted-foreground hover:text-foreground [&::-webkit-details-marker]:hidden">
                <x-ui.icon name="chevron-down" class="size-4 -rotate-90 transition-transform group-open:rotate-0" />
                <x-ui.icon name="file-code" class="size-4" />
                @if ($showFiles && $this->files !== null)
                    {{ count($this->files) }} {{ Str::plural('file', count($this->files)) }} changed
                @else
                    Files changed
                @endif
            </summary>

            @if ($showFiles)
                @if ($this->files === null)
                    <p class="mt-3 text-sm text-muted-foreground">Could not load the files from GitHub.</p>
                @else
                    <ul class="mt-3 divide-y overflow-hidden rounded-md border">
                        @foreach ($this->files as $file)
                            <li wire:key="file-{{ $file['filename'] }}">
                                <details class="group/file" x-data x-on:toggle.once="highlightDiff($el.querySelector('pre'), @js($file['filename']))">
                                    <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-1.5 text-sm hover:bg-muted/50 [&::-webkit-details-marker]:hidden">
                                        <x-ui.icon name="chevron-down" class="size-3.5 shrink-0 -rotate-90 text-muted-foreground transition-transform group-open/file:rotate-0" />
                                        <code class="flex-1 truncate text-xs">{{ $file['filename'] }}</code>
                                        @if ($file['status'] !== 'modified')
                                            <x-ui.badge variant="secondary">{{ $file['status'] }}</x-ui.badge>
                                        @endif
                                        <span class="text-xs tabular-nums text-green-600">+{{ $file['additions'] ?? 0 }}</span>
                                        <span class="text-xs tabular-nums text-destructive">−{{ $file['deletions'] ?? 0 }}</span>
                                    </summary>
                                    @if (isset($file['patch']))
                                        <pre wire:ignore class="overflow-x-auto border-t bg-muted/20 font-mono text-xs leading-5">@foreach (explode("\n", $file['patch']) as $line)<div @class([
                                            'px-3',
                                            'bg-green-500/10' => str_starts_with($line, '+'),
                                            'bg-red-500/10' => str_starts_with($line, '-'),
                                            'bg-blue-500/10 text-muted-foreground' => str_starts_with($line, '@@'),
                                        ])>{{ $line === '' ? ' ' : $line }}</div>@endforeach</pre>
                                    @else
                                        <p class="border-t px-3 py-2 text-xs text-muted-foreground">No diff shown (binary or too large).</p>
                                    @endif
                                </details>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @else
                <ul class="mt-3 grid gap-2" aria-label="Loading files">
                    @foreach ([70, 45, 60] as $width)
                        <li class="h-7 animate-pulse rounded-md bg-muted" style="width: {{ $width }}%"></li>
                    @endforeach
                </ul>
            @endif
        </details>

            @if ($run === null)
            <x-ui.card>
                <div class="flex flex-col items-center gap-2 py-10 text-center">
                    <x-ui.icon name="shield" class="size-8 text-muted-foreground" />
                    <p class="font-medium">Not reviewed yet</p>
                    <p class="max-w-sm text-sm text-muted-foreground">
                        Reviews start automatically on new commits when auto-review is on, or when someone comments
                        <code>{{ '@'.$pullRequest->repository->organization->bot_handle }} review</code>.
                    </p>
                </div>
            </x-ui.card>
            @else
                <x-ui.card>
                    <x-slot:title>
                        {{ $run->agent !== null ? $run->agent->name.' agent' : 'Review' }} #{{ $run->id }}
                    </x-slot:title>
                    <div class="grid gap-6">
                        <div class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <x-ui.badge :variant="$run->status->badgeVariant()">{{ $run->status->label() }}</x-ui.badge>
                            <span>{{ $run->trigger->label() }}{{ $run->triggered_by ? ' by '.$run->triggered_by : '' }}</span>
                            <span>·</span>
                            <code class="text-xs">{{ Str::substr($run->head_sha, 0, 7) }}</code>
                            @if ($run->is_full && $run->agent === null)
                                <x-ui.badge variant="outline">Full review</x-ui.badge>
                            @endif
                            @if ($run->is_dry_run)
                                <x-ui.badge variant="warning">Test run · not posted</x-ui.badge>
                            @endif
                        </div>

                        @if (in_array($run->status, [ReviewRunStatus::Queued, ReviewRunStatus::Running, ReviewRunStatus::Completed, ReviewRunStatus::Failed], true))
                            <x-ui.stepper
                                :steps="ReviewStage::steps()"
                                :current="$run->stage?->value"
                                :state="match ($run->status) {
                                    ReviewRunStatus::Queued => 'pending',
                                    ReviewRunStatus::Completed => 'complete',
                                    ReviewRunStatus::Failed => 'error',
                                    default => 'active',
                                }"
                            />
                        @endif

                        @if ($this->logs->isNotEmpty())
                            <details @if (! $run->status->isFinished()) open @endif class="rounded-md border text-sm">
                                <summary class="cursor-pointer px-3 py-2 font-medium">Live log</summary>
                                <ol
                                    x-data
                                    x-init="$el.scrollTop = $el.scrollHeight; new MutationObserver(() => $el.scrollTop = $el.scrollHeight).observe($el, { childList: true })"
                                    class="max-h-64 overflow-y-auto border-t bg-muted/40 p-3 font-mono text-xs"
                                >
                                    @foreach ($this->logs as $log)
                                        <li wire:key="log-{{ $log->id }}" @class(['text-destructive' => $log->level === 'error'])>
                                            <span class="text-muted-foreground">{{ $log->created_at->format('H:i:s') }}</span>
                                            @if ($call = $log->toolCall())
                                                <span class="ml-4 inline-flex items-center gap-1 rounded border bg-background px-1.5 align-middle font-medium">
                                                    <x-ui.icon :name="match ($call['tool']) { 'read_file' => 'file-code', 'search_code' => 'search', 'symbol_graph' => 'git-branch', default => 'bot' }" class="size-3 text-muted-foreground" />
                                                    {{ $call['tool'] }}
                                                </span>
                                                <span class="break-all">{{ $call['arguments'] }}</span>
                                                @if ($call['outcome'] !== '')
                                                    <span class="text-muted-foreground">→ {{ $call['outcome'] }}</span>
                                                @endif
                                            @else
                                                {{ $log->message }}
                                            @endif
                                        </li>
                                    @endforeach
                                </ol>
                            </details>
                        @endif

                        @if ($run->status === ReviewRunStatus::Failed && $run->error)
                            <x-ui.alert variant="destructive" title="Review failed">{{ Str::limit($run->error, 300) }}</x-ui.alert>
                        @elseif ($run->summary)
                            <div class="prose prose-sm max-w-none dark:prose-invert prose-code:rounded prose-code:bg-muted prose-code:px-1 prose-code:py-0.5 prose-code:font-normal prose-code:before:content-none prose-code:after:content-none">
                                {!! $this->markdown($run->summary) !!}
                            </div>
                        @endif
                    </div>
                </x-ui.card>

                @if ($run->status === ReviewRunStatus::Completed || $this->findingCounts !== [])
                    <x-ui.card title="Findings">
                        <div class="grid gap-4">
                            <div class="inline-flex w-fit flex-wrap rounded-md border p-0.5 text-sm">
                                @foreach ([...array_map(fn ($status) => $status->value, FindingStatus::cases()), 'all'] as $status)
                                    @php($count = $status === 'all' ? array_sum($this->findingCounts) : ($this->findingCounts[$status] ?? 0))
                                    <button
                                        type="button"
                                        wire:click="$set('findingStatus', '{{ $status }}')"
                                        @class([
                                            'rounded px-3 py-1',
                                            'bg-secondary font-medium' => $findingStatus === $status,
                                            'text-muted-foreground hover:text-foreground' => $findingStatus !== $status,
                                        ])
                                    >{{ ucfirst($status) }} <span class="text-muted-foreground tabular-nums">{{ $count }}</span></button>
                                @endforeach
                            </div>

                            @forelse ($this->findings as $finding)
                                <article wire:key="finding-{{ $finding->id }}" class="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-3 rounded-lg border p-4">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-ui.badge :variant="$finding->severity->badgeVariant()">{{ $finding->severity->label() }}</x-ui.badge>
                                        <x-ui.badge variant="outline">{{ $finding->category->label() }}</x-ui.badge>
                                        @if ($finding->agent !== null)
                                            <x-ui.badge variant="secondary"><x-ui.icon name="bot" /> {{ $finding->agent->name }}</x-ui.badge>
                                        @endif
                                        @if ($findingStatus === 'all')
                                            <x-ui.badge :variant="$finding->status->badgeVariant()">{{ $finding->status->label() }}</x-ui.badge>
                                        @endif
                                        <span class="ml-auto text-xs text-muted-foreground tabular-nums">Risk {{ $finding->risk_score }} · {{ $finding->confidence }}% confident</span>
                                    </div>
                                    <h3 class="font-medium">{{ $finding->title }}</h3>
                                    <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-muted-foreground">
                                        <x-ui.icon name="file-code" class="size-3.5" />
                                        <code>{{ $finding->file_path }}</code> {{ $finding->lineLabel() }}
                                        @if ($finding->graph_symbol)
                                            <span>·</span>
                                            <span title="{{ $finding->graph_symbol }}">in <code>{{ Str::afterLast($finding->graph_symbol, '::') }}</code>{{ $finding->graph_callers ? ', used by '.$finding->graph_callers.' '.Str::plural('caller', $finding->graph_callers) : '' }}</span>
                                        @endif
                                        @if ($finding->verification === \App\Enums\FindingVerification::Confirmed)
                                            <x-ui.badge variant="success" class="ml-1"><x-ui.icon name="check" /> Verified</x-ui.badge>
                                        @endif
                                    </p>
                                    @if ($finding->status === FindingStatus::Dismissed && $finding->dismissed_by)
                                        <p class="text-xs text-muted-foreground">Dismissed on GitHub by {{ '@'.$finding->dismissed_by }}.</p>
                                    @endif
                                    @if ($finding->learning !== null)
                                        <x-ui.alert>
                                            <x-slot:icon><x-ui.icon name="lightbulb" /></x-slot:icon>
                                            <span><span class="font-medium text-foreground">Stopped by a team learning:</span> {{ $finding->learning->body }}</span>
                                        </x-ui.alert>
                                    @endif
                                    @if ($finding->signals->isNotEmpty())
                                        <p class="flex flex-wrap gap-1.5">
                                            @foreach ($finding->signals as $signal)
                                                <x-ui.badge :variant="$signal->type->isPositive() ? 'success' : 'outline'">{{ $signal->type->label() }}</x-ui.badge>
                                            @endforeach
                                        </p>
                                    @endif
                                    @if ($finding->status === FindingStatus::Rejected && $finding->verifier_note && $finding->learning === null)
                                        <x-ui.alert>
                                            <x-slot:icon><x-ui.icon name="shield" /></x-slot:icon>
                                            <span class="[&_code]:rounded [&_code]:bg-muted [&_code]:px-1"><span class="font-medium text-foreground">Verifier:</span> {!! Str::inlineMarkdown($finding->verifier_note, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</span>
                                        </x-ui.alert>
                                    @endif
                                    <div class="prose prose-sm max-w-none dark:prose-invert prose-code:rounded prose-code:bg-muted prose-code:px-1 prose-code:py-0.5 prose-code:font-normal prose-code:before:content-none prose-code:after:content-none">{!! $this->markdown($finding->body) !!}</div>
                                    @if ($finding->suggestion)
                                        <div class="grid gap-1">
                                            <span class="text-xs font-medium text-muted-foreground">Suggested change</span>
                                            <pre class="overflow-x-auto rounded-md bg-muted p-3 text-xs"><code>{{ $finding->suggestion }}</code></pre>
                                        </div>
                                    @endif
                                </article>
                            @empty
                                <p class="py-6 text-center text-sm text-muted-foreground">No {{ $findingStatus === 'all' ? '' : $findingStatus.' ' }}findings in this review.</p>
                            @endforelse
                        </div>
                    </x-ui.card>
                @endif

                @if ($this->conversation->isNotEmpty())
                    <x-ui.card title="Conversation" :description="'Questions asked to @'.$pullRequest->repository->organization->bot_handle.' on GitHub.'">
                        <ol class="grid gap-4">
                            @foreach ($this->conversation as $message)
                                @php($fromBot = $message->role === \App\Enums\ConversationRole::Bot)
                                <li wire:key="message-{{ $message->id }}" @class(['grid min-w-0 gap-1.5 rounded-lg border p-3', 'bg-muted/40' => $fromBot, 'ml-6' => $fromBot])>
                                    <div class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        @if ($fromBot)
                                            <x-ui.icon name="bot" class="size-3.5" />
                                        @else
                                            <x-ui.icon name="message-square" class="size-3.5" />
                                        @endif
                                        <span class="font-medium text-foreground">{{ '@'.$message->author_login }}</span>
                                        @if ($message->finding !== null && ! $fromBot)
                                            <span class="truncate">on “{{ Str::limit($message->finding->title, 60) }}”</span>
                                        @elseif ($message->isInThread() && ! $fromBot)
                                            <span>in a review thread</span>
                                        @endif
                                        @if ($message->status === \App\Enums\ConversationStatus::Pending)
                                            <x-ui.badge variant="secondary" class="animate-pulse">Answering…</x-ui.badge>
                                        @elseif ($message->status === \App\Enums\ConversationStatus::Failed)
                                            <x-ui.badge variant="destructive">Failed</x-ui.badge>
                                        @endif
                                        <span class="ml-auto">{{ $message->created_at->diffForHumans(short: true) }}</span>
                                    </div>
                                    <div class="prose prose-sm max-w-none dark:prose-invert prose-code:rounded prose-code:bg-muted prose-code:px-1 prose-code:py-0.5 prose-code:font-normal prose-code:before:content-none prose-code:after:content-none">{!! $this->markdown($message->body) !!}</div>
                                </li>
                            @endforeach
                        </ol>
                    </x-ui.card>
                @endif
            @endif
        </div>

        @if ($run !== null)
            <div class="grid content-start gap-6">
                @if ($run->graph_status)
                    @php($graph = $run->graph_summary ?? [])
                    <x-ui.card title="Impact" :description="$run->graph_status->label()">
                        @if ($run->graph_status === \App\Enums\RunGraphStatus::Ready)
                            <div class="grid gap-4 text-sm">
                                @if ($run->graph_risk_level)
                                    <div class="flex items-center justify-between">
                                        <span class="text-muted-foreground">Structural risk</span>
                                        <x-ui.badge :variant="match ($run->graph_risk_level) { 'HIGH', 'CRITICAL' => 'destructive', 'MEDIUM' => 'warning', default => 'secondary' }">
                                            {{ ucfirst(strtolower($run->graph_risk_level)) }}{{ $run->graph_risk_score !== null ? ' · '.$run->graph_risk_score : '' }}
                                        </x-ui.badge>
                                    </div>
                                @endif
                                <dl class="grid grid-cols-2 gap-x-4 gap-y-2">
                                    <dt class="text-muted-foreground">Changed functions</dt>
                                    <dd class="text-right tabular-nums">{{ count($graph['changed_functions'] ?? []) }}</dd>
                                    <dt class="text-muted-foreground">Impacted</dt>
                                    <dd class="text-right tabular-nums">{{ $graph['impacted_functions'] ?? 0 }} fn · {{ $graph['impacted_files'] ?? 0 }} files</dd>
                                    <dt class="text-muted-foreground">Without tests</dt>
                                    <dd @class(['text-right tabular-nums', 'font-medium text-destructive' => ($graph['test_gaps'] ?? 0) > 0])>{{ $graph['test_gaps'] ?? 0 }}</dd>
                                    <dt class="text-muted-foreground">Affected flows</dt>
                                    <dd class="text-right tabular-nums">{{ $graph['affected_flows'] ?? 0 }}</dd>
                                </dl>
                                @if (! empty($graph['changed_functions']))
                                    <ul class="-mx-1 grid gap-1 border-t pt-3">
                                        @foreach (collect($graph['changed_functions'])->sortByDesc('callers')->take(8) as $function)
                                            <li class="grid gap-0.5 rounded px-1 py-1" title="{{ $function['symbol'] }}">
                                                <div class="flex items-center gap-2">
                                                    <code class="flex-1 truncate text-xs">{{ $function['name'] }}</code>
                                                    <span class="text-xs text-muted-foreground tabular-nums">{{ $function['callers'] }} {{ Str::plural('caller', $function['callers']) }}</span>
                                                    @unless ($function['tested'])
                                                        <span class="size-1.5 shrink-0 rounded-full bg-destructive" title="No tests"></span>
                                                    @endunless
                                                </div>
                                                @foreach ($function['contract'] as $change)
                                                    <span class="truncate text-xs text-muted-foreground">⚠ {{ $change['field'] }}: {{ Str::limit($change['old'], 30) }} → {{ Str::limit($change['new'], 30) }}</span>
                                                @endforeach
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @else
                            <p class="text-sm text-muted-foreground">
                                {{ $graph['error'] ?? 'This review ran on the diff only, without callers and test coverage from the code graph.' }}
                            </p>
                        @endif
                    </x-ui.card>
                @endif

                <x-ui.card title="Details">
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <dt class="text-muted-foreground">Risk score</dt>
                        <dd class="text-right font-medium tabular-nums">{{ $run->risk_score ?? '—' }}</dd>
                        <dt class="text-muted-foreground">Threshold</dt>
                        <dd class="text-right tabular-nums">{{ $run->risk_threshold ?? '—' }}</dd>
                        <dt class="text-muted-foreground">Rejected by verifier</dt>
                        <dd class="text-right tabular-nums">{{ $run->findings_rejected }}</dd>
                        <dt class="text-muted-foreground">Files</dt>
                        <dd class="text-right tabular-nums">{{ $run->files_reviewed }}</dd>
                        @if ($run->diff_metrics !== null)
                            <dt class="text-muted-foreground">Lines</dt>
                            <dd class="text-right tabular-nums"><span class="text-green-600">+{{ $run->diff_metrics['lines_added'] }}</span> <span class="text-destructive">−{{ $run->diff_metrics['lines_removed'] }}</span></dd>
                            <dt class="text-muted-foreground">Comment lines added</dt>
                            <dd class="text-right tabular-nums">{{ $run->diff_metrics['comment_lines'] }}{{ $run->diff_metrics['lines_added'] > 0 ? ' ('.round(100 * $run->diff_metrics['comment_lines'] / $run->diff_metrics['lines_added']).'%)' : '' }}</dd>
                        @endif
                        <dt class="text-muted-foreground">Tokens</dt>
                        <dd class="text-right tabular-nums">{{ number_format($run->tokens_input + $run->tokens_output) }}</dd>
                        <dt class="text-muted-foreground">Estimated cost</dt>
                        <dd class="text-right tabular-nums">{{ $run->is_dry_run ? '—' : \App\Services\Usage\UsageReport::money($run->estimatedCostMicros()) }}</dd>
                        <dt class="text-muted-foreground">Duration</dt>
                        <dd class="text-right tabular-nums">{{ $run->durationInSeconds() !== null ? $run->durationInSeconds().'s' : '—' }}</dd>
                        <dt class="col-span-2 text-muted-foreground">Model</dt>
                        <dd class="col-span-2 truncate text-xs">{{ $run->model ?? '—' }}</dd>
                    </dl>
                </x-ui.card>

                <x-ui.card title="History">
                    <ul class="-mx-2 grid gap-1">
                        @foreach ($this->runs as $item)
                            <li wire:key="run-{{ $item->id }}">
                                <button
                                    type="button"
                                    wire:click="selectRun({{ $item->id }})"
                                    @class([
                                        'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-accent',
                                        'bg-accent' => $item->is($run),
                                    ])
                                >
                                    <span @class([
                                        'size-2 shrink-0 rounded-full',
                                        'bg-success' => $item->status === ReviewRunStatus::Completed,
                                        'bg-destructive' => $item->status === ReviewRunStatus::Failed,
                                        'animate-pulse bg-primary' => in_array($item->status, [ReviewRunStatus::Queued, ReviewRunStatus::Running], true),
                                        'bg-muted-foreground' => in_array($item->status, [ReviewRunStatus::Skipped, ReviewRunStatus::Superseded], true),
                                    ])></span>
                                    <code class="text-xs">{{ Str::substr($item->head_sha, 0, 7) }}</code>
                                    <span class="flex-1 truncate text-muted-foreground">{{ $item->agent?->name ?? $item->trigger->label() }}{{ $item->is_dry_run ? ' · test' : '' }}</span>
                                    <span class="text-xs text-muted-foreground">{{ $item->created_at->diffForHumans(short: true) }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            </div>
        @endif
    </div>
</div>
