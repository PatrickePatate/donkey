<?php

use App\Models\Organization;
use App\Models\PullRequest;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Pull requests')] class extends Component
{
    use WithPagination;

    #[Url(except: 'open')]
    public string $state = 'open';

    #[Url(except: '')]
    public string $search = '';

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            "echo-private:organizations.{$this->organization->id},.review.updated" => '$refresh',
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedState(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function organization(): Organization
    {
        return auth()->user()->currentOrganization;
    }

    /**
     * @return LengthAwarePaginator<int, PullRequest>
     */
    #[Computed]
    public function pullRequests(): LengthAwarePaginator
    {
        return PullRequest::query()
            ->whereIn('repository_id', $this->organization->repositories()->select('id'))
            ->when($this->state !== 'all', fn ($query) => $query->where('state', $this->state))
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('title', "%{$this->search}%")
                ->orWhereLike('author_login', "%{$this->search}%")))
            ->with(['repository', 'latestReviewRun'])
            ->withCount(['findings as published_findings_count' => fn ($query) => $query->where('status', 'published')])
            ->latest('updated_at')
            ->paginate(20);
    }
};
?>

<div class="grid gap-6">
    <div class="grid gap-1">
        <h1 class="text-2xl font-semibold tracking-tight">Pull requests</h1>
        <p class="text-sm text-muted-foreground">Reviews across every connected repository, updated live.</p>
    </div>

    <x-ui.card>
        <div class="grid gap-4">
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative w-full max-w-sm">
                    <x-ui.icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground" />
                    <x-ui.input wire:model.live.debounce.300ms="search" placeholder="Search by title or author…" class="pl-9" />
                </div>
                <div class="inline-flex rounded-md border p-0.5 text-sm">
                    @foreach (['open' => 'Open', 'merged' => 'Merged', 'closed' => 'Closed', 'all' => 'All'] as $value => $label)
                        <button
                            type="button"
                            wire:click="$set('state', '{{ $value }}')"
                            @class([
                                'rounded px-3 py-1',
                                'bg-secondary font-medium' => $state === $value,
                                'text-muted-foreground hover:text-foreground' => $state !== $value,
                            ])
                        >{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            @if ($this->pullRequests->isEmpty())
                <div class="flex flex-col items-center gap-2 py-12 text-center">
                    <x-ui.icon name="git-pull-request" class="size-8 text-muted-foreground" />
                    <p class="font-medium">No pull requests</p>
                    <p class="max-w-sm text-sm text-muted-foreground">Pull requests appear here as soon as GitHub tells us about them.</p>
                </div>
            @else
                <ul class="divide-y">
                    @foreach ($this->pullRequests as $pullRequest)
                        @php($run = $pullRequest->latestReviewRun)
                        <li wire:key="pr-{{ $pullRequest->id }}">
                            <a href="{{ route('pull-requests.show', $pullRequest) }}" wire:navigate class="-mx-2 flex flex-wrap items-center gap-3 rounded-md px-2 py-3 transition-colors hover:bg-muted/50">
                                <x-ui.icon name="git-pull-request" @class(['size-5', 'text-success' => $pullRequest->state === 'open', 'text-muted-foreground' => $pullRequest->state !== 'open']) />
                                <div class="grid min-w-0 flex-1 gap-0.5">
                                    <span class="truncate font-medium">{{ $pullRequest->title }}</span>
                                    <span class="truncate text-xs text-muted-foreground">
                                        {{ $pullRequest->repository->full_name }}#{{ $pullRequest->number }} · {{ $pullRequest->author_login }} · {{ $pullRequest->updated_at->diffForHumans() }}
                                    </span>
                                </div>
                                @if ($pullRequest->published_findings_count > 0)
                                    <x-ui.badge variant="outline">{{ $pullRequest->published_findings_count }} {{ str('finding')->plural($pullRequest->published_findings_count) }}</x-ui.badge>
                                @endif
                                @if ($run)
                                    <x-ui.badge :variant="$run->status->badgeVariant()">
                                        {{ $run->status === \App\Enums\ReviewRunStatus::Running && $run->stage ? $run->stage->label().'…' : $run->status->label() }}
                                    </x-ui.badge>
                                @else
                                    <x-ui.badge variant="secondary">Not reviewed</x-ui.badge>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>

                {{ $this->pullRequests->links() }}
            @endif
        </div>
    </x-ui.card>
</div>
