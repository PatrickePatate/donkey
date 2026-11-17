@php
    $user = auth()->user();
    $organization = $user->currentOrganization;
    $navigation = [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'layout-dashboard'],
        ['label' => 'Pull requests', 'route' => 'pull-requests.*', 'href' => 'pull-requests.index', 'icon' => 'git-pull-request'],
        ['label' => 'Repositories', 'route' => 'repositories.index', 'icon' => 'git-branch'],
        ['label' => 'Agents', 'route' => 'agents.*', 'href' => 'agents.index', 'icon' => 'bot'],
        ['label' => 'Knowledge', 'route' => 'knowledge.*', 'href' => 'knowledge.index', 'icon' => 'lightbulb'],
    ];
    $settings = [
        ['label' => 'Organization', 'route' => 'settings.organization', 'icon' => 'settings'],
        ['label' => 'Members', 'route' => 'settings.members', 'icon' => 'users'],
    ];

    if ($organization && $user->can('viewUsage', $organization)) {
        $settings[] = ['label' => 'Usage & cost', 'route' => 'settings.usage', 'icon' => 'activity'];
    }

    if ($organization && $user->can('viewAudit', $organization)) {
        $settings[] = ['label' => 'Audit log', 'route' => 'settings.audit', 'icon' => 'shield'];
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
    <link rel="icon" href="{{ asset('favicon.png') }}" type="image/png">
</head>
<body class="min-h-svh" @if ($organization) data-organization-id="{{ $organization->id }}" @endif>
    <div x-data="{ sidebarOpen: false }" class="flex min-h-svh">
        <div x-cloak x-show="sidebarOpen" x-on:click="sidebarOpen = false" class="fixed inset-0 z-30 bg-black/40 lg:hidden"></div>

        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r bg-sidebar transition-transform lg:sticky lg:top-0 lg:h-svh lg:translate-x-0"
            x-bind:class="sidebarOpen && 'translate-x-0'"
        >
            <div class="p-3">
                @if ($organization)
                    <x-ui.dropdown align="start" width="w-58">
                        <x-slot:trigger>
                            <button type="button" class="flex w-full items-center gap-2 rounded-md p-2 text-left hover:bg-accent">
                                <x-organization-avatar :organization="$organization" />
                                <span class="grid flex-1 text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ $organization->name }}</span>
                                    <span class="truncate text-xs text-muted-foreground">{{ $user->roleIn($organization)?->label() }}</span>
                                </span>
                                <x-ui.icon name="chevrons-up-down" class="text-muted-foreground" />
                            </button>
                        </x-slot:trigger>

                        <p class="px-2 py-1.5 text-xs text-muted-foreground">Organizations</p>
                        @foreach ($user->organizations()->orderBy('name')->get() as $item)
                            <form method="POST" action="{{ route('organizations.switch', $item) }}">
                                @csrf
                                @method('PUT')
                                <x-ui.dropdown-item type="submit">
                                    <x-organization-avatar :organization="$item" size="size-6 text-xs" />
                                    <span class="flex-1 truncate text-left">{{ $item->name }}</span>
                                    @if ($item->is($organization))
                                        <x-ui.icon name="check" />
                                    @endif
                                </x-ui.dropdown-item>
                            </form>
                        @endforeach
                        <x-ui.separator class="my-1" />
                        <x-ui.dropdown-item :href="route('organizations.create')" wire:navigate>
                            <span class="flex size-6 items-center justify-center rounded-md border"><x-ui.icon name="plus" /></span> New organization
                        </x-ui.dropdown-item>
                    </x-ui.dropdown>
                @endif
            </div>

            <nav class="flex flex-1 flex-col gap-6 px-3 py-2 text-sm">
                @foreach (['Platform' => $navigation, 'Settings' => $settings] as $group => $items)
                    <div class="grid gap-1">
                        <p class="px-2 text-xs font-medium text-muted-foreground">{{ $group }}</p>
                        @foreach ($items as $item)
                            <a
                                href="{{ route($item['href'] ?? $item['route']) }}"
                                wire:navigate
                                @class([
                                    'flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-accent hover:text-accent-foreground',
                                    'bg-accent font-medium text-accent-foreground' => request()->routeIs($item['route']),
                                ])
                            >
                                <x-ui.icon :name="$item['icon']" />
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </nav>

            <div class="border-t p-3">
                <x-ui.dropdown align="top" width="w-58">
                    <x-slot:trigger>
                        <button type="button" class="flex w-full items-center gap-2 rounded-md p-2 text-left hover:bg-accent">
                            <x-ui.avatar :initials="$user->initials()" />
                            <span class="grid flex-1 text-sm leading-tight">
                                <span class="truncate font-medium">{{ $user->name }}</span>
                                <span class="truncate text-xs text-muted-foreground">{{ $user->email }}</span>
                            </span>
                            <x-ui.icon name="chevrons-up-down" class="text-muted-foreground" />
                        </button>
                    </x-slot:trigger>

                    <x-ui.dropdown-item x-on:click="
                        const dark = ! document.documentElement.classList.contains('dark');
                        document.documentElement.classList.toggle('dark', dark);
                        localStorage.setItem('theme', dark ? 'dark' : 'light');
                    ">
                        <x-ui.icon name="moon" /> Toggle theme
                    </x-ui.dropdown-item>
                    <x-ui.separator class="my-1" />
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-ui.dropdown-item type="submit">
                            <x-ui.icon name="log-out" /> Log out
                        </x-ui.dropdown-item>
                    </form>
                </x-ui.dropdown>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-14 items-center gap-2 border-b px-4 lg:hidden">
                <x-ui.button variant="ghost" size="icon" x-on:click="sidebarOpen = true">
                    <x-ui.icon name="layout-dashboard" />
                    <span class="sr-only">Open navigation</span>
                </x-ui.button>
                <span class="font-semibold">{{ config('app.name') }}</span>
            </header>

            <main class="mx-auto w-full max-w-6xl flex-1 p-4 md:p-8">
                {{ $slot }}
            </main>
        </div>
    </div>

    <x-ui.toaster />
    @livewireScripts
</body>
</html>
