@props([
    'title' => null,
    'description' => null,
])

<div {{ $attributes->class('flex flex-col gap-6 rounded-xl border bg-card py-6 text-card-foreground shadow-xs') }}>
    @if ($title || $description || isset($actions))
        <div class="flex items-start justify-between gap-4 px-6">
            <div class="grid gap-1.5">
                @if ($title)
                    <h3 class="text-sm leading-none">{{ $title }}</h3>
                @endif
                @if ($description)
                    <p class="text-sm text-muted-foreground">{{ $description }}</p>
                @endif
            </div>
            {{ $actions ?? '' }}
        </div>
    @endif

    <div class="px-6">
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="flex items-center gap-2 border-t px-6 pt-6">{{ $footer }}</div>
    @endisset
</div>
