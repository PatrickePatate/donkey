<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::guest')] #[Title('Sign up')] class extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $this->email = (string) request()->query('email', '');
    }

    public function register(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = User::query()->create($validated);

        event(new Registered($user));
        Auth::login($user);
        session()->regenerate();

        $this->redirectIntended(route('verification.notice'), navigate: true);
    }
};
?>

<x-ui.card title="Create an account" description="Start reviewing pull requests in minutes">
    <form wire:submit="register" class="grid gap-6">
        <x-ui.field label="Name" for="name" error="name">
            <x-ui.input id="name" wire:model="name" autocomplete="name" autofocus required />
        </x-ui.field>

        <x-ui.field label="Email" for="email" error="email">
            <x-ui.input id="email" type="email" wire:model="email" autocomplete="email" required />
        </x-ui.field>

        <x-ui.field label="Password" for="password" error="password">
            <x-ui.input id="password" type="password" wire:model="password" autocomplete="new-password" required />
        </x-ui.field>

        <x-ui.field label="Confirm password" for="password_confirmation">
            <x-ui.input id="password_confirmation" type="password" wire:model="password_confirmation" autocomplete="new-password" required />
        </x-ui.field>

        <x-ui.button type="submit" class="w-full">Create account</x-ui.button>

        <p class="text-center text-sm text-muted-foreground">
            Already registered?
            <a href="{{ route('login') }}" wire:navigate class="text-foreground underline underline-offset-4">Log in</a>
        </p>
    </form>
</x-ui.card>
