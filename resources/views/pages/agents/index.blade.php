<?php

use App\Models\Organization;
use App\Models\ReviewAgent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Agents')] class extends Component
{
    #[Computed]
    public function organization(): Organization
    {
        return auth()->user()->currentOrganization;
    }

    /**
     * @return Collection<int, ReviewAgent>
     */
    #[Computed]
    public function agents(): Collection
    {
        return $this->organization->reviewAgents()
            ->with('repository')
            ->orderByRaw('repository_id is not null')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, array{name: string, description: string}>
     */
    #[Computed]
    public function templates(): array
    {
        return config('agents.templates');
    }

    public function toggle(int $agentId, bool $enabled): void
    {
        $agent = $this->organization->reviewAgents()->findOrFail($agentId);

        Gate::authorize('update', $agent);

        $agent->update(['enabled' => $enabled]);
        unset($this->agents);

        $this->dispatch('toast', message: $enabled ? "{$agent->name} enabled." : "{$agent->name} disabled.", variant: 'success');
    }
};
?>

<div class="grid gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="grid gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">Agents</h1>
            <p class="text-sm text-muted-foreground">
                Specialist reviewers with your own instructions. Run one with
                <code class="rounded bg-muted px-1.5 py-0.5 text-xs">{{ '@'.$this->organization->bot_handle }} run &lt;agent&gt;</code>
                on a pull request, or let it join automatic reviews.
            </p>
        </div>

        @can('create', [App\Models\ReviewAgent::class, $this->organization])
            <x-ui.dropdown align="end" width="w-72">
                <x-slot:trigger>
                    <x-ui.button><x-ui.icon name="plus" /> New agent</x-ui.button>
                </x-slot:trigger>

                <x-ui.dropdown-item :href="route('agents.create')" wire:navigate>Blank agent</x-ui.dropdown-item>
                <div class="my-1 h-px bg-border"></div>
                <p class="px-2 py-1 text-xs text-muted-foreground">From a template</p>
                @foreach ($this->templates as $key => $template)
                    <x-ui.dropdown-item :href="route('agents.create', ['template' => $key])" wire:navigate>
                        <span class="grid">
                            <span>{{ $template['name'] }}</span>
                            <span class="text-xs text-muted-foreground">{{ $template['description'] }}</span>
                        </span>
                    </x-ui.dropdown-item>
                @endforeach
            </x-ui.dropdown>
        @endcan
    </div>

    <x-ui.card>
        @if ($this->agents->isEmpty())
            <div class="flex flex-col items-center gap-2 py-12 text-center">
                <x-ui.icon name="bot" class="size-8 text-muted-foreground" />
                <p class="font-medium">No agents yet</p>
                <p class="max-w-sm text-sm text-muted-foreground">
                    Start from a template such as Security or Laravel, then tune its instructions to your codebase.
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-muted-foreground">
                            <th class="h-10 px-2 font-medium">Agent</th>
                            <th class="h-10 px-2 font-medium">Scope</th>
                            <th class="h-10 px-2 font-medium">Files</th>
                            <th class="h-10 px-2 font-medium">Runs</th>
                            <th class="h-10 px-2 font-medium">Enabled</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->agents as $agent)
                            <tr wire:key="agent-{{ $agent->id }}" class="border-b last:border-0">
                                <td class="p-2">
                                    <a href="{{ route('agents.edit', $agent) }}" wire:navigate class="grid gap-0.5 hover:underline">
                                        <span class="font-medium">{{ $agent->name }}</span>
                                        <span class="font-mono text-xs text-muted-foreground">{{ $agent->slug }}</span>
                                    </a>
                                </td>
                                <td class="p-2">
                                    @if ($agent->repository_id === null)
                                        <x-ui.badge variant="secondary">Organization</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="outline">{{ $agent->repository?->full_name }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="max-w-56 truncate p-2 font-mono text-xs text-muted-foreground">
                                    {{ blank($agent->file_globs) ? 'All files' : implode(', ', $agent->file_globs) }}
                                </td>
                                <td class="p-2">
                                    {{ $agent->run_on_auto_review ? 'Automatic + on demand' : 'On demand' }}
                                </td>
                                <td class="p-2">
                                    <x-ui.switch
                                        :checked="$agent->enabled"
                                        :disabled="! auth()->user()->can('update', $agent)"
                                        x-on:switch-change="$wire.toggle({{ $agent->id }}, $event.detail)"
                                        aria-label="Enable {{ $agent->name }}"
                                    />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
