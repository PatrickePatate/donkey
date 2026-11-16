@props(['invalid' => false])

<input {{ $attributes->class([
    'flex h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none md:text-sm',
    'placeholder:text-muted-foreground file:border-0 file:bg-transparent file:text-sm file:font-medium',
    'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
    'disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30',
    'border-destructive ring-destructive/20' => $invalid,
]) }} />
