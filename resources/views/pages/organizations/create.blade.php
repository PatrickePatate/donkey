<?php

use App\Actions\CreateOrganization;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts::guest')] #[Title('Create organization')] class extends Component
{
    #[Validate('required|string|min:2|max:80')]
    public string $name = '';

    public function create(CreateOrganization $createOrganization): void
    {
        $this->validate();

        $createOrganization->handle(auth()->user(), $this->name);

        session()->flash('success', 'Organization created. Connect GitHub to get started.');

        $this->redirectRoute('repositories.index', navigate: true);
    }
};
?>

<x-ui.card title="Create an organization" description="Organizations group your repositories, members and review settings.">
    <form wire:submit="create" class="grid gap-6">
        <x-ui.field label="Organization name" for="name" error="name">
            <x-ui.input id="name" wire:model="name" placeholder="Acme Inc." autofocus required />
        </x-ui.field>

        <x-ui.button type="submit" class="w-full"><x-ui.icon name="plus" /> Create organization</x-ui.button>

        @if (auth()->user()->organizations()->exists())
            <x-ui.button variant="ghost" :href="route('dashboard')" wire:navigate>Cancel</x-ui.button>
        @endif
    </form>
</x-ui.card>
