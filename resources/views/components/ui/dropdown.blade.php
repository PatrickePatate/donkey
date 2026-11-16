@props(['align' => 'end', 'width' => 'w-56'])

<div x-data="{ open: false }" x-on:keydown.escape.window="open = false" class="relative" {{ $attributes }}>
    <div x-on:click="open = ! open">{{ $trigger }}</div>

    <div
        x-cloak
        x-show="open"
        x-transition.opacity.duration.100ms
        x-on:click.outside="open = false"
        @class([
            'absolute z-50 mt-2 overflow-hidden rounded-md border bg-popover p-1 text-popover-foreground shadow-md',
            $width,
            'right-0' => $align === 'end',
            'left-0' => $align === 'start',
            'bottom-full mb-2' => $align === 'top',
        ])
    >
        {{ $slot }}
    </div>
</div>
