{{-- A GitHub login, with the avatar of the Donkey member who linked it. The login stays the label: links are self-declared, not verified. --}}
@props(['login', 'avatar' => false, 'at' => false])

@php($member = auth()->user()?->currentOrganization?->memberByGithubLogin($login))

<span {{ $attributes->class('inline-flex items-center gap-2') }} title="{{ $member ? $member->name.' (linked by them)' : '@'.$login }}">
    @if ($avatar)
        <x-ui.avatar class="size-6 text-[10px]" :src="$member?->avatarUrl()" :initials="$member?->initials() ?? str($login)->substr(0, 2)->upper()" />
    @endif
    <span>{{ ($at ? '@' : '').$login }}</span>
</span>
