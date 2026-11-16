<x-mail::message>
# Join {{ $organizationName }}

You've been invited to join **{{ $organizationName }}** as **{{ $role }}**.

<x-mail::button :url="$url">
Accept invitation
</x-mail::button>

This invitation expires in 7 days.
</x-mail::message>
