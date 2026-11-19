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
        <h1 class="text-2xl tracking-tight">Pull requests</h1>
        <p class="text-sm text-muted-foreground">Reviews across every connected repository, updated live.</p>
    </div>

    <div class="table-wrap">
        <div class="table-toolbar">
            <label class="table-search">
                <x-ui.icon name="search" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search by title or author…" aria-label="Search pull requests">
            </label>
            <div class="inline-flex rounded-md bg-muted p-0.5 text-sm">
                @foreach (['open' => 'Open', 'merged' => 'Merged', 'closed' => 'Closed', 'all' => 'All'] as $value => $label)
                    <button
                        type="button"
                        wire:click="$set('state', '{{ $value }}')"
                        @class([
                            'h-7 cursor-pointer rounded px-3 font-medium transition-colors',
                            'bg-background text-foreground shadow-xs' => $state === $value,
                            'text-muted-foreground hover:text-foreground' => $state !== $value,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>
            <span class="ml-auto text-xs text-muted-foreground tabular-nums">{{ $this->pullRequests->total() }} {{ str('pull request')->plural($this->pullRequests->total()) }}</span>
        </div>

        @if ($this->pullRequests->isEmpty())
            <div class="flex flex-col items-center gap-2 py-12 text-center">
                <x-ui.icon name="git-pull-request" class="size-8 text-muted-foreground" />
                <p class="font-medium">No pull requests</p>
                <p class="max-w-sm text-sm text-muted-foreground">Pull requests appear here as soon as GitHub tells us about them.</p>
            </div>
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
                        @foreach ($this->pullRequests as $pullRequest)
                            @include('partials.pull-request-row', ['pullRequest' => $pullRequest])
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($this->pullRequests->hasPages())
                <div class="table-foot">{{ $this->pullRequests->links() }}</div>
            @endif
        @endif
    </div>
</div>
