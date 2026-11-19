<?php

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Profile')] class extends Component
{
    use WithFileUploads;

    /** @var ?\Livewire\Features\SupportFileUploads\TemporaryUploadedFile */
    public $avatar = null;

    public string $name = '';

    public string $githubLogin = '';

    public function mount(): void
    {
        $this->name = auth()->user()->name;
        $this->githubLogin = (string) auth()->user()->github_login;
    }

    public function updatedAvatar(): void
    {
        $this->validate(['avatar' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:max_width=2048,max_height=2048']]);

        $user = auth()->user();
        $previous = $user->avatar_path;
        $user->update(['avatar_path' => $this->avatar->store('user-avatars', 'public')]);
        $previous && Storage::disk('public')->delete($previous);

        // Reload so the sidebar, outside this component, shows the new avatar.
        session()->flash('success', 'Avatar updated.');
        $this->redirectRoute('settings.profile', navigate: true);
    }

    public function removeAvatar(): void
    {
        $user = auth()->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        $this->redirectRoute('settings.profile', navigate: true);
    }

    public function save(): void
    {
        $this->githubLogin = Str::lower(ltrim(trim($this->githubLogin), '@'));

        $validated = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'githubLogin' => ['nullable', 'string', 'max:39', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i', Rule::unique('users', 'github_login')->ignore(auth()->id())],
        ], ['githubLogin.unique' => 'Another member has already linked this GitHub username.']);

        auth()->user()->update([
            'name' => $validated['name'],
            'github_login' => filled($validated['githubLogin']) ? $validated['githubLogin'] : null,
        ]);

        $this->dispatch('toast', message: 'Profile saved.', variant: 'success');
    }
};
?>

<div class="grid max-w-5xl gap-8">
    <div class="flex flex-wrap items-center gap-4">
        <x-ui.avatar class="size-12 text-base" :src="auth()->user()->avatarUrl()" :initials="auth()->user()->initials()" />
        <div class="grid gap-1">
            <h1 class="text-2xl tracking-tight">Profile</h1>
            <p class="text-sm text-muted-foreground">How you appear to your teammates in Donkey.</p>
        </div>
    </div>

    <form wire:submit="save" class="grid border-t">
        <section class="grid gap-6 py-8 md:grid-cols-[15rem_minmax(0,1fr)] md:gap-10">
            <div class="grid content-start gap-1">
                <h2 class="text-base">General</h2>
                <p class="text-sm text-muted-foreground">Your GitHub username lets Donkey show you, instead of your GitHub login, on pull requests and comments.</p>
            </div>
            <div class="grid divide-y rounded-xl border bg-card shadow-xs">
                <div class="p-5">
                    <x-ui.field label="Avatar" for="avatar" error="avatar" hint="PNG, JPG or WebP, up to 1 MB. A square image works best.">
                        <div class="flex flex-wrap items-center gap-4">
                            <x-ui.avatar class="size-14 text-lg" :src="auth()->user()->avatarUrl()" :initials="auth()->user()->initials()" />
                            <input id="avatar" type="file" wire:model="avatar" accept="image/png,image/jpeg,image/webp" class="text-sm file:mr-3 file:cursor-pointer file:rounded-md file:border file:bg-background file:px-3 file:py-1.5 file:text-sm file:font-medium">
                            @if (auth()->user()->avatar_path)
                                <x-ui.button type="button" variant="ghost" wire:click="removeAvatar"><x-ui.icon name="trash" /> Remove</x-ui.button>
                            @endif
                        </div>
                    </x-ui.field>
                </div>
                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <x-ui.field label="Name" for="name" error="name">
                        <x-ui.input id="name" wire:model="name" required />
                    </x-ui.field>
                    <x-ui.field label="GitHub username" for="githubLogin" error="githubLogin">
                        <div class="flex items-center">
                            <span class="flex h-9 items-center rounded-l-md border border-r-0 bg-muted px-3 text-sm text-muted-foreground">@</span>
                            <x-ui.input id="githubLogin" wire:model="githubLogin" class="rounded-l-none" placeholder="octocat" />
                        </div>
                    </x-ui.field>
                </div>
            </div>
        </section>
        <div class="flex justify-end border-t pt-6">
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save"><x-ui.icon name="save" /> Save</x-ui.button>
        </div>
    </form>
</div>
