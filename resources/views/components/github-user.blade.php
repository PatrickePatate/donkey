{{-- A GitHub login, shown as the Donkey member who linked it when there is one in the current organization. --}}
@props(['login', 'avatar' => false, 'at' => false])

@php($member = auth()->user()?->currentOrganization?->memberByGithubLogin($login))

<span {{ $attributes->class('inline-flex items-center gap-2') }} title="{{ '@'.$login }}">
    @if ($avatar)
        <x-ui.avatar class="size-6 text-[10px]" :src="$member?->avatarUrl()" :initials="$member?->initials() ?? str($login)->substr(0, 2)->upper()" />
    @endif
    <span>{{ $member?->name ?? ($at ? '@' : '').$login }}</span>
</span>
