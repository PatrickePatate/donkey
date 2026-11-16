{{-- Listens for window "toast" events: $dispatch('toast', { message, variant }) or Livewire $this->dispatch('toast', message: ..., variant: ...). --}}
<div
    x-data="{
        toasts: [],
        add(detail) {
            const toast = { id: Date.now() + Math.random(), message: detail.message, variant: detail.variant ?? 'default' };
            this.toasts.push(toast);
            setTimeout(() => this.toasts = this.toasts.filter(t => t.id !== toast.id), 4000);
        },
    }"
    x-init="
        @if (session('success')) add({ message: @js(session('success')), variant: 'success' }); @endif
        @if (session('error')) add({ message: @js(session('error')), variant: 'destructive' }); @endif
    "
    x-on:toast.window="add($event.detail)"
    class="pointer-events-none fixed right-4 bottom-4 z-[60] flex w-full max-w-sm flex-col gap-2"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-transition
            class="pointer-events-auto flex items-start gap-3 rounded-lg border bg-popover p-4 text-sm text-popover-foreground shadow-lg"
        >
            <span
                class="mt-1.5 size-2 shrink-0 rounded-full"
                :class="{ 'bg-success': toast.variant === 'success', 'bg-destructive': toast.variant === 'destructive', 'bg-muted-foreground': toast.variant === 'default' }"
            ></span>
            <p x-text="toast.message"></p>
        </div>
    </template>
</div>
