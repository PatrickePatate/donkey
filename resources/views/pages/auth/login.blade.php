<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts::guest')] #[Title('Log in')] class extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate();

        $throttleKey = Str::transliterate(Str::lower($this->email).'|'.request()->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($throttleKey), 'minutes' => 1]),
            ]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($throttleKey);
        session()->regenerate();

        $this->redirectIntended(route('dashboard'), navigate: true);
    }
};
?>

<x-ui.card title="Welcome back" description="Log in to your account">
    <form wire:submit="login" class="grid gap-6">
        <x-ui.field label="Email" for="email" error="email">
            <x-ui.input id="email" type="email" wire:model="email" autocomplete="email" autofocus required :invalid="$errors->has('email')" />
        </x-ui.field>

        <x-ui.field label="Password" for="password" error="password">
            <x-ui.input id="password" type="password" wire:model="password" autocomplete="current-password" required />
        </x-ui.field>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model="remember" class="size-4 rounded border-input accent-primary">
            Remember me
        </label>

        <x-ui.button type="submit" class="w-full">Log in</x-ui.button>

        <p class="text-center text-sm text-muted-foreground">
            No account yet?
            <a href="{{ route('register') }}" wire:navigate class="text-foreground underline underline-offset-4">Sign up</a>
        </p>
    </form>
</x-ui.card>
