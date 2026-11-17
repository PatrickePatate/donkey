{{--
    With `context` (a CSS selector such as "tr"), right-clicking the closest matching ancestor also
    opens the menu, at the cursor.
--}}
@props(['align' => 'end', 'width' => 'w-56', 'context' => null])

<div
    x-data="{ open: false, at: null }"
    @if ($context)
        x-init="$el.closest(@js($context)).addEventListener('contextmenu', (event) => {
            event.preventDefault();
            $dispatch('context-menu-open', { el: $el });
            at = { x: event.clientX, y: event.clientY };
            open = true;
            $nextTick(() => {
                const menu = $refs.panel.getBoundingClientRect();
                at = { x: Math.min(at.x, innerWidth - menu.width - 8), y: Math.min(at.y, innerHeight - menu.height - 8) };
            });
        })"
        x-on:context-menu-open.window="$event.detail.el !== $el && (open = false)"
        x-on:scroll.window="at && (open = false)"
    @endif
    x-effect="open || (at = null)"
    x-on:keydown.escape.window="open = false"
    class="relative"
    {{ $attributes }}
>
    <div x-on:click="at = null; open = ! open">{{ $trigger }}</div>

    <div
        x-cloak
        x-ref="panel"
        x-show="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-on:click.outside="open = false"
        x-bind:style="at && { position: 'fixed', left: at.x + 'px', top: at.y + 'px', right: 'auto', bottom: 'auto', marginTop: 0, transformOrigin: 'top left' }"
        @class([
            'absolute z-50 mt-2 overflow-hidden rounded-md border bg-popover p-1 text-left text-popover-foreground shadow-md',
            $width,
            'right-0' => $align === 'end',
            'left-0' => $align === 'start',
            'bottom-full mb-2' => $align === 'top',
        ])
    >
        {{ $slot }}
    </div>
</div>
