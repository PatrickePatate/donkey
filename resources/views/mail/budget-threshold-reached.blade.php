<x-mail::message>
# {{ $reached ? 'Monthly AI budget reached' : "{$percent}% of the monthly AI budget used" }}

**{{ $organizationName }}** has spent **${{ $spent }}** of its **${{ $budget }}** AI budget this month.

@if ($reached)
Reviews, answers on GitHub and learning proposals are paused until next month, or until you raise the budget.
@else
When the budget is reached, Donkey stops starting new reviews and answering questions until next month.
@endif

<x-mail::button :url="$url">
See usage and cost
</x-mail::button>
</x-mail::message>
