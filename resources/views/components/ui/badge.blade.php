@props(['variant' => 'default'])

@php
    $variants = [
        'default' => 'border-transparent bg-primary text-primary-foreground',
        'secondary' => 'border-transparent bg-secondary text-secondary-foreground',
        'outline' => 'text-foreground',
        'destructive' => 'border-transparent bg-destructive/10 text-destructive',
        'success' => 'border-transparent bg-success/15 text-success',
        'warning' => 'border-transparent bg-warning/20 text-foreground',
    ];
@endphp

<span {{ $attributes->class([
    'inline-flex w-fit shrink-0 items-center justify-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap [&>svg]:size-3',
    $variants[$variant] ?? $variants['default'],
]) }}>{{ $slot }}</span>
