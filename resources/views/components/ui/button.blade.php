@props([
    'variant' => 'default',
    'size' => 'default',
    'href' => null,
    'type' => 'button',
])

@php
    $base = 'inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-[color,background-color,box-shadow,transform] duration-150 active:scale-[0.97] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 data-loading:opacity-60 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0 cursor-pointer';

    $variants = [
        'default' => 'bg-primary text-primary-foreground shadow-xs hover:bg-primary/90',
        'secondary' => 'bg-secondary text-secondary-foreground shadow-xs hover:bg-secondary/80',
        'outline' => 'border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground',
        'ghost' => 'hover:bg-accent hover:text-accent-foreground',
        'destructive' => 'bg-destructive text-white shadow-xs hover:bg-destructive/90',
        'link' => 'text-primary underline-offset-4 hover:underline',
    ];

    $sizes = [
        'sm' => 'h-8 px-3 has-[>svg]:px-2.5',
        'default' => 'h-9 px-4 py-2 has-[>svg]:px-3',
        'lg' => 'h-10 px-6 has-[>svg]:px-4',
        'icon' => 'size-9',
    ];

    $classes = implode(' ', [$base, $variants[$variant] ?? $variants['default'], $sizes[$size] ?? $sizes['default']]);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
