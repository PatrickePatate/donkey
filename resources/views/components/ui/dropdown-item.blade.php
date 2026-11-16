@props(['href' => null, 'variant' => 'default', 'type' => 'button'])

@php
    $classes = 'relative flex w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-none select-none hover:bg-accent hover:text-accent-foreground [&_svg]:size-4 [&_svg]:text-muted-foreground'
        .($variant === 'destructive' ? ' text-destructive hover:bg-destructive/10 hover:text-destructive' : '');
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
