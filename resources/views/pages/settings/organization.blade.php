<?php

use App\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Organization settings')] class extends Component
{
    public string $name = '';

    public int $riskThreshold = 50;

    public bool $autoReviewDefault = true;

    public string $botHandle = '';

    public function mount(): void
    {
        $this->name = $this->organization->name;
        $this->riskThreshold = $this->organization->risk_threshold;
        $this->autoReviewDefault = $this->organization->auto_review_default;
        $this->botHandle = $this->organization->bot_handle;
    }

    #[Computed]
    public function organization(): Organization
    {
        return auth()->user()->currentOrganization;
    }

    public function save(): void
    {
        Gate::authorize('update', $this->organization);

        $validated = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'riskThreshold' => ['required', 'integer', 'between:0,100'],
            'autoReviewDefault' => ['boolean'],
            'botHandle' => ['required', 'string', 'max:39', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i'],
        ]);

        $this->organization->update([
            'name' => $validated['name'],
            'risk_threshold' => $validated['riskThreshold'],
            'auto_review_default' => $validated['autoReviewDefault'],
            'bot_handle' => $validated['botHandle'],
        ]);

        $this->dispatch('toast', message: 'Settings saved.', variant: 'success');
    }
};
?>

<div class="grid max-w-2xl gap-6">
    <div class="grid gap-1">
        <h1 class="text-2xl font-semibold tracking-tight">Organization settings</h1>
        <p class="text-sm text-muted-foreground">Defaults apply to every repository unless a repository overrides them.</p>
    </div>

    @php($canUpdate = auth()->user()->can('update', $this->organization))

    <form wire:submit="save">
        <fieldset @disabled(! $canUpdate) class="grid gap-6">
            <x-ui.card title="General">
                <div class="grid gap-6">
                    <x-ui.field label="Name" for="name" error="name">
                        <x-ui.input id="name" wire:model="name" required />
                    </x-ui.field>

                    <x-ui.field label="Bot handle" for="botHandle" error="botHandle" :hint="'Mention it in a PR comment, e.g. @'.($botHandle ?: 'donkey').' review.'">
                        <div class="flex items-center">
                            <span class="flex h-9 items-center rounded-l-md border border-r-0 bg-muted px-3 text-sm text-muted-foreground">@</span>
                            <x-ui.input id="botHandle" wire:model.blur="botHandle" class="rounded-l-none" required />
                        </div>
                    </x-ui.field>
                </div>
            </x-ui.card>

            <x-ui.card title="Reviews">
                <div class="grid gap-6">
                    <div class="flex items-center justify-between gap-4">
                        <div class="grid gap-1">
                            <x-ui.label>Auto-review new repositories</x-ui.label>
                            <p class="text-sm text-muted-foreground">Review pull requests automatically when they're opened or updated.</p>
                        </div>
                        <x-ui.switch wire:model="autoReviewDefault" :checked="$autoReviewDefault" :disabled="! $canUpdate" />
                    </div>

                    <x-ui.separator />

                    <x-ui.field label="Risk threshold" error="riskThreshold" hint="Findings scoring below this are kept in the dashboard but not posted on GitHub. 0 posts everything.">
                        <div class="flex items-center gap-4" x-data>
                            <input type="range" min="0" max="100" step="5" wire:model.live="riskThreshold" class="w-full accent-primary">
                            <span class="w-10 text-right text-sm font-medium tabular-nums">{{ $riskThreshold }}</span>
                        </div>
                    </x-ui.field>
                </div>

                @if ($canUpdate)
                    <x-slot:footer>
                        <x-ui.button type="submit">Save changes</x-ui.button>
                    </x-slot:footer>
                @endif
            </x-ui.card>
        </fieldset>
    </form>
</div>
