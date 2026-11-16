<?php

use App\Actions\AcceptInvitation;
use App\Models\Invitation;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::guest')] #[Title('Invitation')] class extends Component
{
    #[Locked]
    public Invitation $invitation;

    public function mount(string $token): void
    {
        $this->invitation = Invitation::query()->with('organization')->where('token', $token)->firstOrFail();

        if (auth()->guest()) {
            redirect()->setIntendedUrl(request()->fullUrl());
        }
    }

    public function accept(AcceptInvitation $acceptInvitation): void
    {
        abort_if(auth()->guest(), 403);

        try {
            $acceptInvitation->handle($this->invitation, auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('invitation', $exception->getMessage());

            return;
        }

        session()->flash('success', "Welcome to {$this->invitation->organization->name}!");

        $this->redirectRoute('dashboard', navigate: true);
    }
};
?>

<x-ui.card :title="'Join '.$invitation->organization->name" :description="'You have been invited as '.$invitation->role->label().'.'">
    <div class="grid gap-4">
        @error('invitation')
            <x-ui.alert variant="destructive">{{ $message }}</x-ui.alert>
        @enderror

        @if (! $invitation->isPending())
            <x-ui.alert variant="destructive" title="Invitation unavailable">This invitation has expired or was already used.</x-ui.alert>
        @elseif (auth()->guest())
            <p class="text-sm text-muted-foreground">Log in or create an account with <span class="font-medium text-foreground">{{ $invitation->email }}</span> to accept.</p>
            <div class="grid grid-cols-2 gap-2">
                <x-ui.button variant="outline" :href="route('login')">Log in</x-ui.button>
                <x-ui.button :href="route('register', ['email' => $invitation->email])">Sign up</x-ui.button>
            </div>
        @else
            <x-ui.button wire:click="accept" class="w-full">Accept invitation</x-ui.button>
        @endif
    </div>
</x-ui.card>
