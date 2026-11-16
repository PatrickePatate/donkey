{{--
    Horizontal (vertical on small screens) progress stepper.

    steps:   list of ['key' => string, 'label' => string, 'description' => ?string]
    current: key of the active step, or null
    state:   'pending' (nothing started), 'active', 'complete' (all done) or 'error' (current step failed)
--}}
@props([
    'steps' => [],
    'current' => null,
    'state' => 'active',
])

@php
    $keys = array_column($steps, 'key');
    $currentIndex = $current === null ? null : array_search($current, $keys, true);

    $stepState = function (int $index) use ($state, $currentIndex): string {
        return match (true) {
            $state === 'complete' => 'complete',
            $state === 'pending' || $currentIndex === null || $currentIndex === false => 'upcoming',
            $index < $currentIndex => 'complete',
            $index === $currentIndex => $state === 'error' ? 'error' : 'current',
            default => 'upcoming',
        };
    };
@endphp

<ol {{ $attributes->class('flex flex-col gap-4 md:flex-row md:items-start md:gap-0') }}>
    @foreach ($steps as $index => $step)
        @php($status = $stepState($index))
        <li
            @class(['relative flex gap-3 md:flex-1 md:flex-col md:items-center md:gap-2 md:text-center'])
            data-state="{{ $status }}"
            @if ($status === 'current') aria-current="step" @endif
        >
            @unless ($loop->last)
                <span
                    aria-hidden="true"
                    @class([
                        'absolute top-8 left-4 h-[calc(100%-1rem)] w-px -translate-x-1/2 md:top-4 md:left-[calc(50%+1.25rem)] md:h-px md:w-[calc(100%-2.5rem)] md:translate-x-0',
                        'bg-primary' => $status === 'complete',
                        'bg-border' => $status !== 'complete',
                    ])
                ></span>
            @endunless

            <span @class([
                'relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full border-2 text-xs font-semibold transition-colors',
                'border-primary bg-primary text-primary-foreground' => $status === 'complete',
                'border-primary bg-background text-primary' => $status === 'current',
                'border-destructive bg-destructive text-white' => $status === 'error',
                'border-border bg-background text-muted-foreground' => $status === 'upcoming',
            ])>
                @if ($status === 'complete')
                    <x-ui.icon name="check" class="size-4" />
                @elseif ($status === 'error')
                    <x-ui.icon name="x" class="size-4" />
                @elseif ($status === 'current')
                    <span class="size-2.5 animate-pulse rounded-full bg-primary"></span>
                @else
                    {{ $index + 1 }}
                @endif
            </span>

            <span class="grid gap-0.5 pt-1 md:px-2 md:pt-0">
                <span @class([
                    'text-sm font-medium',
                    'text-muted-foreground' => $status === 'upcoming',
                    'text-destructive' => $status === 'error',
                ])>{{ $step['label'] }}</span>
                @if (! empty($step['description']))
                    <span class="text-xs text-muted-foreground">{{ $step['description'] }}</span>
                @endif
            </span>
        </li>
    @endforeach
</ol>
