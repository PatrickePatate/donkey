@props(['initials' => '?'])

<span {{ $attributes->class('flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted text-xs font-medium') }}>
    {{ $initials }}
</span>
