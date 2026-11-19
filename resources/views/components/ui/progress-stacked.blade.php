{{-- Several shares of one total on a single bar, with a legend. Colours cycle through the --chart-* tokens. --}}
@props(['segments', 'label'])

@php
    /** @var list<array{label: string, value: int}> $segments */
    $total = max(1, array_sum(array_column($segments, 'value')));
@endphp

<div {{ $attributes->class('grid gap-2.5') }}>
    <div role="img" aria-label="{{ $label }}: {{ collect($segments)->map(fn ($segment) => $segment['label'].' '.$segment['value'])->join(', ') }}" class="flex h-3 gap-0.5 overflow-hidden rounded-full bg-muted">
        @foreach ($segments as $segment)
            <span class="h-full transition-[width] duration-500" style="width: {{ 100 * $segment['value'] / $total }}%; background: var(--chart-{{ $loop->index % 6 + 1 }})" title="{{ $segment['label'] }}: {{ $segment['value'] }}"></span>
        @endforeach
    </div>
    <ul class="flex flex-wrap gap-x-4 gap-y-1.5 text-xs">
        @foreach ($segments as $segment)
            <li class="inline-flex items-center gap-1.5">
                <span class="size-2.5 rounded-[3px]" style="background: var(--chart-{{ $loop->index % 6 + 1 }})"></span>
                {{ $segment['label'] }}
                <span class="tabular-nums text-muted-foreground">{{ $segment['value'] }} · {{ round(100 * $segment['value'] / $total) }}%</span>
            </li>
        @endforeach
    </ul>
</div>
