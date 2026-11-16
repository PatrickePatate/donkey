<?php

use App\Models\Repository;
use App\Services\Documentation\MarkdownDocument;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Repository documentation')] class extends Component
{
    public int $repositoryId;

    public bool $embedded = false;

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

    /**
     * @return array{html: string, toc: list<array{level: int, id: string, title: string}>}
     */
    #[Computed]
    public function document(): array
    {
        return app(MarkdownDocument::class)->render((string) $this->repository->document?->content);
    }
};
?>

<div class="grid gap-6">
    @unless ($embedded)
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="grid gap-1">
                <a href="{{ route('repositories.show', $this->repository) }}" wire:navigate class="text-sm text-muted-foreground hover:text-foreground">{{ $this->repository->full_name }}</a>
                <h1 class="text-2xl font-semibold tracking-tight">Documentation</h1>
            </div>
        </div>
    @endunless

    <p class="text-sm text-muted-foreground">
        @if ($this->repository->document?->generated_at)
            Generated {{ $this->repository->document->generated_at->diffForHumans() }} from <code>{{ Str::substr((string) $this->repository->document->commit_sha, 0, 7) }}</code>.
        @else
            Documentation has not been generated yet.
        @endif
    </p>

    @if (filled($this->repository->document?->content))
        @php($document = $this->document)
        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_15rem]">
            <x-ui.card class="min-w-0">
                <article class="prose prose-neutral max-w-none overflow-x-auto px-6 dark:prose-invert prose-headings:scroll-mt-6 prose-h2:border-b prose-h2:pb-2 prose-pre:bg-muted prose-pre:text-foreground prose-code:rounded prose-code:bg-muted prose-code:px-1 prose-code:py-0.5 prose-code:font-normal prose-code:before:content-none prose-code:after:content-none prose-pre:prose-code:bg-transparent prose-pre:prose-code:p-0 prose-table:text-sm">
                    {!! $document['html'] !!}
                </article>
            </x-ui.card>

            @if (count($document['toc']) > 1)
                <nav
                    aria-label="On this page"
                    class="order-first sticky top-6 max-h-[calc(100vh-3rem)] overflow-y-auto text-sm lg:order-last"
                    x-data="{ active: null }"
                    x-init="
                        const headings = [...document.querySelectorAll('article [id]')].filter(el => /^H[23]$/.test(el.tagName));
                        const observer = new IntersectionObserver(entries => {
                            const visible = entries.find(entry => entry.isIntersecting);
                            if (visible) active = visible.target.id;
                        }, { rootMargin: '0px 0px -75% 0px' });
                        headings.forEach(heading => observer.observe(heading));
                    "
                >
                    <p class="mb-2 font-medium">On this page</p>
                    <ul class="grid gap-1 border-l">
                        @foreach ($document['toc'] as $item)
                            <li>
                                <a
                                    href="#{{ $item['id'] }}"
                                    @class(['block border-l-2 border-transparent py-0.5 text-muted-foreground hover:text-foreground', 'pl-3' => $item['level'] === 2, 'pl-6' => $item['level'] === 3])
                                    :class="active === '{{ $item['id'] }}' && 'border-primary! font-medium text-foreground'"
                                    style="margin-left: -1px"
                                >{{ $item['title'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        </div>
    @else
        <x-ui.alert title="No documentation yet">
            <p>Enable automatic documentation for this repository to generate its architecture guide after a merge.</p>
        </x-ui.alert>
    @endif
</div>
