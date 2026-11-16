<?php

use App\Actions\AutoJoinOrganizations;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::guest')] #[Title('Verify your email')] class extends Component
{
    public bool $sent = false;

    public function mount(): void
    {
        if (auth()->user()->hasVerifiedEmail()) {
            $this->redirectRoute('dashboard', navigate: true);
        }
    }

    public function resend(): void
    {
        $key = 'verify-email:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('email', 'Too many emails sent. Try again in a few minutes.');

            return;
        }

        RateLimiter::hit($key, 600);
        auth()->user()->sendEmailVerificationNotification();
        $this->sent = true;
    }

    /**
     * Organizations the user will join once their email is verified.
     *
     * @return list<string>
     */
    public function joinableOrganizations(): array
    {
        return AutoJoinOrganizations::organizationsFor(auth()->user()->email)->pluck('name')->all();
    }
};
?>

<x-ui.card title="Verify your email" :description="'We sent a link to '.auth()->user()->email.'.'">
    <div class="grid gap-4 text-sm">
        @if ($organizations = $this->joinableOrganizations())
            <x-ui.alert title="Your team is already here">
                Once your email is verified you will join {{ implode(', ', $organizations) }} automatically.
            </x-ui.alert>
        @endif

        <p class="text-muted-foreground">Click the link in the email to confirm it's yours. Check your spam folder if it doesn't arrive.</p>

        @if ($sent)
            <p class="text-success">A new link is on its way.</p>
        @endif
        @error('email')
            <p class="text-destructive">{{ $message }}</p>
        @enderror

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button wire:click="resend" variant="outline">Send the link again</x-ui.button>
            <x-ui.button :href="route('organizations.create')" variant="ghost" wire:navigate>Skip for now</x-ui.button>
        </div>
    </div>
</x-ui.card>
