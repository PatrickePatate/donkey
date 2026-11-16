@props(['variant' => 'default', 'title' => null])

<div role="alert" {{ $attributes->class([
    'relative grid w-full grid-cols-[0_1fr] items-start gap-y-0.5 rounded-lg border px-4 py-3 text-sm has-[>svg]:grid-cols-[calc(var(--spacing)*4)_1fr] has-[>svg]:gap-x-3 [&>svg]:size-4 [&>svg]:translate-y-0.5',
    'bg-card text-card-foreground' => $variant === 'default',
    'bg-card text-destructive [&>svg]:text-current' => $variant === 'destructive',
]) }}>
    {{ $icon ?? '' }}
    @if ($title)
        <div class="col-start-2 font-medium tracking-tight">{{ $title }}</div>
    @endif
    <div class="col-start-2 grid gap-1 text-muted-foreground">{{ $slot }}</div>
</div>
