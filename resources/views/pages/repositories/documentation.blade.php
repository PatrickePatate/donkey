<?php

use App\Models\Repository;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Repository documentation')] class extends Component
{
    public int $repositoryId;

    public function mount(int $repository): void
    {
        $this->repositoryId = $repository;
    }

    #[Computed]
    public function repository(): Repository
    {
        return auth()->user()->currentOrganization->repositories()
            ->with('document')
            ->findOrFail($this->repositoryId);
    }

    #[Computed]
    public function documentHtml(): string
    {
        return Str::markdown((string) $this->repository->document?->content, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }
};
?>

<div class="grid gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="grid gap-1">
            <a href="{{ route('repositories.index') }}" wire:navigate class="text-sm text-muted-foreground hover:text-foreground">Repositories</a>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $this->repository->full_name }} documentation</h1>
            <p class="text-sm text-muted-foreground">
                @if ($this->repository->document?->generated_at)
                    Generated {{ $this->repository->document->generated_at->diffForHumans() }} from <code>{{ Str::substr((string) $this->repository->document->commit_sha, 0, 7) }}</code>.
                @else
                    Documentation has not been generated yet.
                @endif
            </p>
        </div>
        <x-ui.button variant="outline" :href="route('repositories.index')" wire:navigate>
            Back to repositories
        </x-ui.button>
    </div>

    @if (filled($this->repository->document?->content))
        <x-ui.card>
            <article class="prose prose-neutral dark:prose-invert max-w-none overflow-x-auto">
                {!! $this->documentHtml !!}
            </article>
        </x-ui.card>
    @else
        <x-ui.alert title="No documentation yet">
            <p>Enable automatic documentation for this repository to generate its architecture guide after a merge.</p>
        </x-ui.alert>
    @endif
</div>