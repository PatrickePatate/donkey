{{-- Open with $dispatch('open-dialog', 'name') / Livewire $this->dispatch('open-dialog', 'name'); close with 'close-dialog'. --}}
@props(['name', 'title' => null, 'description' => null])

<div
    x-data="{ open: false }"
    x-on:open-dialog.window="if ($event.detail === @js($name) || $event.detail?.[0] === @js($name)) open = true"
    x-on:close-dialog.window="open = false"
    x-on:keydown.escape.window="open = false"
>
    <template x-teleport="body">
        <div x-cloak x-show="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div x-show="open" x-transition.opacity class="fixed inset-0 bg-black/50" x-on:click="open = false"></div>

            <div
                x-show="open"
                x-transition
                role="dialog"
                aria-modal="true"
                {{ $attributes->class('relative z-50 grid w-full max-w-lg gap-4 rounded-lg border bg-background p-6 shadow-lg') }}
            >
                @if ($title || $description)
                    <div class="grid gap-2">
                        @if ($title)
                            <h2 class="text-lg leading-none">{{ $title }}</h2>
                        @endif
                        @if ($description)
                            <p class="text-sm text-muted-foreground">{{ $description }}</p>
                        @endif
                    </div>
                @endif

                {{ $slot }}

                <button type="button" x-on:click="open = false" class="absolute top-4 right-4 rounded-xs opacity-70 transition-opacity hover:opacity-100">
                    <x-ui.icon name="x" class="size-4" />
                    <span class="sr-only">Close</span>
                </button>
            </div>
        </div>
    </template>
</div>
