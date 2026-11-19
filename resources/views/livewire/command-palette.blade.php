<?php

use App\Models\Organization;
use App\Models\PullRequest;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $query = '';

    /**
     * Results grouped by heading. An item opens `href`, submits `form` (a PUT/POST route), or runs a client `action`.
     *
     * @return array<string, list<array{label: string, icon: string, hint?: string, href?: string, form?: array{action: string, method: string}, action?: string}>>
     */
    #[Computed]
    public function groups(): array
    {
        $user = auth()->user();
        $organization = $user->currentOrganization;
        $term = trim($this->query);

        $groups = [
            'Pull requests' => $this->pullRequests($organization, $term),
            'Repositories' => $organization?->repositories()
                ->when($term !== '', fn ($query) => $query->whereLike('full_name', "%{$term}%"))
                ->orderBy('full_name')->limit(5)->get()
                ->map(fn ($repository) => ['label' => $repository->full_name, 'icon' => 'git-branch', 'href' => route('repositories.show', $repository)])
                ->all() ?? [],
            'Agents' => $term === '' ? [] : ($organization?->reviewAgents()
                ->whereLike('name', "%{$term}%")->orderBy('name')->limit(5)->get()
                ->map(fn ($agent) => ['label' => $agent->name, 'icon' => 'bot', 'hint' => $agent->slug, 'href' => route('agents.edit', $agent)])
                ->all() ?? []),
            'Pages' => $this->filter([
                ['label' => 'Dashboard', 'icon' => 'layout-dashboard', 'href' => route('dashboard')],
                ['label' => 'Pull requests', 'icon' => 'git-pull-request', 'href' => route('pull-requests.index')],
                ['label' => 'Repositories', 'icon' => 'git-branch', 'href' => route('repositories.index')],
                ['label' => 'Security', 'icon' => 'shield', 'href' => route('security.index')],
                ['label' => 'Agents', 'icon' => 'bot', 'href' => route('agents.index')],
                ['label' => 'Knowledge', 'icon' => 'lightbulb', 'href' => route('knowledge.index')],
                ['label' => 'Organization settings', 'icon' => 'settings', 'href' => route('settings.organization')],
                ['label' => 'Members', 'icon' => 'users', 'href' => route('settings.members')],
                ...($organization && $user->can('viewUsage', $organization) ? [['label' => 'Usage & cost', 'icon' => 'activity', 'href' => route('settings.usage')]] : []),
                ...($organization && $user->can('viewAudit', $organization) ? [['label' => 'Audit log', 'icon' => 'shield', 'href' => route('settings.audit')]] : []),
                ['label' => 'Profile', 'icon' => 'user', 'href' => route('settings.profile')],
            ], $term),
            'Actions' => $this->filter([
                ...($organization && $user->can('manageMembers', $organization) ? [['label' => 'Invite member', 'icon' => 'user-plus', 'href' => route('settings.members', ['invite' => 1])]] : []),
                ['label' => 'Create agent', 'icon' => 'plus', 'href' => route('agents.create')],
                ['label' => 'Connect GitHub repositories', 'icon' => 'git-branch', 'href' => route('github.install')],
                ['label' => 'New organization', 'icon' => 'plus', 'href' => route('organizations.create')],
                ...$user->organizations()->orderBy('name')->get()
                    ->reject(fn (Organization $item) => $item->is($organization))
                    ->map(fn (Organization $item) => ['label' => "Switch to {$item->name}", 'icon' => 'chevrons-up-down', 'form' => ['action' => route('organizations.switch', $item), 'method' => 'PUT']])
                    ->all(),
                ['label' => 'Toggle theme', 'icon' => 'moon', 'action' => 'theme'],
                ['label' => 'Log out', 'icon' => 'log-out', 'form' => ['action' => route('logout'), 'method' => 'POST']],
            ], $term),
        ];

        return array_filter($groups);
    }

    /**
     * Matches "#12" or "12" against the number, anything else against the title.
     *
     * @return list<array{label: string, icon: string, hint: string, href: string}>
     */
    private function pullRequests(?Organization $organization, string $term): array
    {
        if ($organization === null) {
            return [];
        }

        $number = ltrim($term, '#');

        return PullRequest::query()
            ->with('repository')
            ->whereIn('repository_id', $organization->repositories()->select('id'))
            ->when($term !== '', fn ($query) => ctype_digit($number)
                ? $query->where('number', (int) $number)
                : $query->whereLike('title', "%{$term}%"))
            ->orderByRaw("state = 'open' desc")
            ->latest('updated_at')
            ->limit($term === '' ? 3 : 6)
            ->get()
            ->map(fn (PullRequest $pullRequest) => [
                'label' => $pullRequest->title,
                'icon' => $pullRequest->state === 'open' ? 'git-pull-request' : 'git-merge',
                'hint' => Str::afterLast($pullRequest->repository->full_name, '/')."#{$pullRequest->number}",
                'href' => route('pull-requests.show', $pullRequest),
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function filter(array $items, string $term): array
    {
        return array_values(array_filter($items, fn ($item) => $term === '' || Str::contains($item['label'], $term, ignoreCase: true)));
    }
};
?>

<div
    x-data="{
        open: false,
        active: 0,
        items() { return [...this.$refs.list.querySelectorAll('[data-command]')] },
        show() { this.open = true; this.active = 0; this.$nextTick(() => this.$refs.input.focus()) },
        move(step) {
            const items = this.items();
            if (! items.length) return;
            this.active = (this.active + step + items.length) % items.length;
            items[this.active].scrollIntoView({ block: 'nearest' });
        },
        run(element) {
            this.open = false;
            if (element.dataset.action === 'theme') {
                const dark = ! document.documentElement.classList.contains('dark');
                document.documentElement.classList.toggle('dark', dark);
                localStorage.setItem('theme', dark ? 'dark' : 'light');
            } else if (element.tagName === 'FORM') {
                element.submit();
            } else {
                element.click();
            }
        },
    }"
    x-on:keydown.window.prevent.cmd.k="open ? open = false : show()"
    x-on:keydown.window.prevent.ctrl.k="open ? open = false : show()"
    x-on:open-command-palette.window="show()"
    x-on:keydown.escape.window="open = false"
>
    <template x-teleport="body">
        <div x-cloak x-show="open" class="fixed inset-0 z-50 flex items-start justify-center p-4 pt-[12vh]">
            <div x-show="open" x-transition.opacity class="fixed inset-0 bg-black/40" x-on:click="open = false"></div>

            <div
                x-show="open"
                x-transition
                role="dialog"
                aria-modal="true"
                aria-label="Command palette"
                class="relative z-50 flex max-h-[70vh] w-full max-w-xl flex-col overflow-hidden rounded-xl border bg-popover text-popover-foreground shadow-2xl"
            >
                <div class="flex items-center gap-2 border-b px-4">
                    <x-ui.icon name="search" class="text-muted-foreground" />
                    <input
                        x-ref="input"
                        wire:model.live.debounce.200ms="query"
                        x-on:input="active = 0"
                        x-on:keydown.arrow-down.prevent="move(1)"
                        x-on:keydown.arrow-up.prevent="move(-1)"
                        x-on:keydown.enter.prevent="items()[active] && run(items()[active])"
                        type="text"
                        placeholder="Search pull requests, repositories, pages or actions…"
                        class="h-12 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        autocomplete="off"
                        role="combobox"
                        aria-expanded="true"
                    />
                    <x-ui.icon name="loader-circle" class="animate-spin text-muted-foreground" wire:loading wire:target="query" />
                    <kbd class="rounded border px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground">Esc</kbd>
                </div>

                <div x-ref="list" class="overflow-y-auto p-2" role="listbox">
                    @forelse ($this->groups as $group => $items)
                        <p class="px-2 pt-2 pb-1 text-xs font-medium tracking-wider text-muted-foreground uppercase">{{ $group }}</p>
                        @foreach ($items as $item)
                            @php($classes = 'flex w-full cursor-pointer items-center gap-3 rounded-md px-2 py-2 text-left text-sm')
                            @php($bind = "x-bind:class=\"items().indexOf(\$el) === active && 'bg-accent text-accent-foreground'\" x-on:mousemove=\"active = items().indexOf(\$el)\"")
                            @if (isset($item['form']))
                                <form method="POST" action="{{ $item['form']['action'] }}" data-command wire:key="cmd-{{ $group }}-{{ $loop->index }}" x-on:click="run($el)" {!! $bind !!} class="{{ $classes }}" role="option">
                                    @csrf
                                    @method($item['form']['method'])
                                    @include('livewire.partials.command-item', ['item' => $item])
                                </form>
                            @elseif (isset($item['action']))
                                <button type="button" data-command data-action="{{ $item['action'] }}" wire:key="cmd-{{ $group }}-{{ $loop->index }}" x-on:click="run($el)" {!! $bind !!} class="{{ $classes }}" role="option">
                                    @include('livewire.partials.command-item', ['item' => $item])
                                </button>
                            @else
                                <a href="{{ $item['href'] }}" wire:navigate data-command wire:key="cmd-{{ $group }}-{{ $loop->index }}" x-on:click="open = false" {!! $bind !!} class="{{ $classes }}" role="option">
                                    @include('livewire.partials.command-item', ['item' => $item])
                                </a>
                            @endif
                        @endforeach
                    @empty
                        <p class="py-10 text-center text-sm text-muted-foreground">Nothing matches "{{ $query }}".</p>
                    @endforelse
                </div>

                <div class="flex items-center gap-4 border-t px-4 py-2 text-xs text-muted-foreground">
                    <span class="flex items-center gap-1"><kbd class="rounded border px-1 font-mono">↑</kbd><kbd class="rounded border px-1 font-mono">↓</kbd> navigate</span>
                    <span class="flex items-center gap-1"><kbd class="rounded border px-1 font-mono"><x-ui.icon name="corner-down-left" class="size-3" /></kbd> open</span>
                    <span class="ml-auto">Type <kbd class="rounded border px-1 font-mono">#12</kbd> to jump to a pull request</span>
                </div>
            </div>
        </div>
    </template>
</div>
