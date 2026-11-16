<?php

namespace App\Jobs;

use App\Enums\FindingStatus;
use App\Enums\SignalType;
use App\Models\PullRequest;
use App\Models\ReviewFinding;
use App\Services\GitHub\PullRequestClient;
use App\Services\Knowledge\SignalRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * When a pull request closes, read the reactions on the bot's comments and note findings merged untouched.
 * GitHub sends no webhook for reactions, so this is the only time they are collected.
 */
class CollectFindingFeedback implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public function __construct(public PullRequest $pullRequest)
    {
        $this->onQueue('github');
    }

    public function handle(PullRequestClient $client, SignalRecorder $signals): void
    {
        $pullRequest = $this->pullRequest->fresh('repository.installation');

        if ($pullRequest === null || $pullRequest->isOpen()) {
            return;
        }

        $findings = $pullRequest->findings()
            ->where('status', FindingStatus::Published)
            ->whereNotNull('github_comment_id')
            ->whereHas('reviewRun', fn ($query) => $query->where('is_dry_run', false))
            ->with('signals')
            ->get();

        $ignoredOnMerge = 0;

        foreach ($findings as $finding) {
            $this->recordReactions($client, $signals, $finding);

            $hasPositiveFeedback = $finding->signals()->whereIn('type', SignalType::positiveValues())->exists();

            if ($pullRequest->state === 'merged' && ! $hasPositiveFeedback) {
                $signal = $signals->record($finding, SignalType::IgnoredOnMerge);
                $ignoredOnMerge += $signal->wasRecentlyCreated ? 1 : 0;
            }
        }

        if ($ignoredOnMerge > 0) {
            ProposeLearnings::dispatchFor($pullRequest->repository);
        }
    }

    private function recordReactions(PullRequestClient $client, SignalRecorder $signals, ReviewFinding $finding): void
    {
        try {
            $comment = $client->reviewComment($this->pullRequest, (int) $finding->github_comment_id);
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        if (($comment['reactions']['+1'] ?? 0) > 0) {
            $signals->record($finding, SignalType::ThumbsUp);
        }

        if (($comment['reactions']['-1'] ?? 0) > 0) {
            $signals->record($finding, SignalType::ThumbsDown);
        }
    }
}
