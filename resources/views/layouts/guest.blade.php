<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-svh">
    <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
        <a href="{{ url('/') }}" class="flex items-center gap-2 font-semibold" wire:navigate>
            <span class="flex size-7 items-center justify-center rounded-md bg-primary text-primary-foreground">
                <x-ui.icon name="shield" class="size-4" />
            </span>
            {{ config('app.name') }}
        </a>
        <div class="w-full max-w-sm">
            {{ $slot }}
        </div>
    </div>

    <x-ui.toaster />
    @livewireScripts
</body>
</html>
