@props(['organization', 'size' => 'size-8 text-sm'])

<span {{ $attributes->class(['flex shrink-0 items-center justify-center overflow-hidden rounded-md bg-primary font-semibold text-primary-foreground', $size]) }}>
    @if ($organization->avatarUrl())
        <img src="{{ $organization->avatarUrl() }}" alt="" class="size-full object-cover">
    @else
        {{ $organization->initials() }}
    @endif
</span>
