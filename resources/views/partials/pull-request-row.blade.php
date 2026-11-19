{{-- A pull request row of the .table lists; needs latestReviewRun and published_findings_count loaded. --}}
@php($run = $pullRequest->latestReviewRun)
<tr wire:key="pr-{{ $pullRequest->id }}" class="relative">
    <td class="max-w-0 w-full">
        <div class="flex items-center gap-3">
            <span @class([
                'grid size-8 shrink-0 place-items-center rounded-lg',
                'bg-success/15 text-success' => $pullRequest->state === 'open',
                'bg-[color-mix(in_oklch,var(--chart-1)_15%,transparent)] text-[var(--chart-1)]' => $pullRequest->state === 'merged',
                'bg-muted text-muted-foreground' => ! in_array($pullRequest->state, ['open', 'merged'], true),
            ]) title="{{ ucfirst($pullRequest->state) }}">
                <x-ui.icon :name="$pullRequest->state === 'merged' ? 'git-merge' : 'git-pull-request'" />
            </span>
            <div class="grid min-w-0">
                <a href="{{ route('pull-requests.show', $pullRequest) }}" wire:navigate class="truncate font-medium after:absolute after:inset-0">{{ $pullRequest->title }}</a>
                <span class="truncate text-xs text-muted-foreground">{{ ($showRepository ?? true) ? $pullRequest->repository->full_name : '' }}#{{ $pullRequest->number }}</span>
            </div>
        </div>
    </td>
    <td>
        <x-github-user :login="$pullRequest->author_login" avatar class="whitespace-nowrap text-muted-foreground" />
    </td>
    <td>
        @if ($run)
            <x-ui.badge :variant="$run->status->badgeVariant()">
                {{ $run->status === \App\Enums\ReviewRunStatus::Running && $run->stage ? $run->stage->label().'…' : $run->status->label() }}
            </x-ui.badge>
        @else
            <x-ui.badge variant="outline">Not reviewed</x-ui.badge>
        @endif
    </td>
    <td class="num">
        @if ($pullRequest->published_findings_count > 0)
            <span class="font-medium">{{ $pullRequest->published_findings_count }}</span>
        @else
            <span class="text-muted-foreground">—</span>
        @endif
    </td>
    <td class="num whitespace-nowrap text-muted-foreground">{{ $pullRequest->updated_at->diffForHumans(short: true) }}</td>
</tr>
