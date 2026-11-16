@props([
    'label' => null,
    'for' => null,
    'error' => null,
    'hint' => null,
])

<div {{ $attributes->class('grid content-start gap-2') }}>
    @if ($label)
        <x-ui.label :for="$for">{{ $label }}</x-ui.label>
    @endif

    {{ $slot }}

    @if ($error && $errors->has($error))
        <p class="text-sm text-destructive">{{ $errors->first($error) }}</p>
    @elseif ($hint)
        <p class="text-sm text-muted-foreground">{{ $hint }}</p>
    @endif
</div>
