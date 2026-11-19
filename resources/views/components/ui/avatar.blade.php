@props(['initials' => '?', 'src' => null])

<span {{ $attributes->class('flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted text-xs font-medium') }}>
    @if ($src)
        <img src="{{ $src }}" alt="" class="size-full object-cover">
    @else
        {{ $initials }}
    @endif
</span>
