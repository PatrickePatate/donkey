<?php

namespace App\Services\Review;

use App\Enums\FindingStatus;
use App\Enums\RunGraphStatus;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
use App\Services\GitHub\PullRequestClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Turns stored findings into a GitHub review with inline comments and suggestions.
 */
class ReviewPublisher
{
    public function __construct(private PullRequestClient $client)
    {
    }

    /**
     * Post the "review in progress" comment, or reuse the one an earlier run of the same pull request and agent left.
     */
    public function begin(ReviewRun $run): void
    {
        if ($run->is_dry_run) {
            return;
        }

        $run->github_comment_id ??= ReviewRun::query()
            ->where('pull_request_id', $run->pull_request_id)
            ->where('review_agent_id', $run->review_agent_id)
            ->whereNotNull('github_comment_id')
            ->latest('id')
            ->value('github_comment_id');

        $link = route('pull-requests.show', ['pullRequest' => $run->pull_request_id, 'run' => $run->id]);

        $this->syncComment($run, "{$this->heading($run)}\n\n⏳ Reviewing `".substr($run->head_sha, 0, 7)."`… [follow it live]({$link})");
    }

    /**
     * Replace the progress comment with the outcome of a run that ended without publishing a review.
     */
    public function conclude(ReviewRun $run, string $message): void
    {
        if (!$run->is_dry_run) {
            $this->syncComment($run, "{$this->heading($run)}\n\n{$message}");
        }
    }

    /**
     * @param  Collection<int, ReviewFinding>  $findings  Every finding of the run, whatever its status.
     */
    public function publish(ReviewRun $run, Collection $findings, string $summary): void
    {
        $toPublish = $findings->where('status', FindingStatus::Published)->values();
        $body = $this->summaryBody($run, $findings, $summary);

        if ($toPublish->isNotEmpty()) {
            $this->publishInlineReview($run, $toPublish);
        }

        $this->syncComment($run, $body);
    }

    /**
     * @param  Collection<int, ReviewFinding>  $toPublish
     */
    private function publishInlineReview(ReviewRun $run, Collection $toPublish): void
    {
        $pullRequest = $run->pullRequest;

        try {
            $review = $this->client->createReview($pullRequest, $run->head_sha, '', $toPublish->map(fn (ReviewFinding $finding): array => $this->comment($finding))->all());
        } catch (RequestException $exception) {
            if ($exception->response->status() !== 422) {
                throw $exception;
            }

            // GitHub refused at least one inline position: fall back to listing findings in the review body.
            $review = $this->client->createReview($pullRequest, $run->head_sha, $this->inlineFallback($toPublish), []);

            $run->update(['github_review_id' => $review['id'] ?? null]);

            return;
        }

        $run->update(['github_review_id' => $review['id'] ?? null]);

        if (isset($review['id'])) {
            $this->linkComments($run, $toPublish, (int) $review['id']);
        }
    }

    /**
     * Edit the run's comment, creating it when it is missing or was deleted on GitHub.
     */
    private function syncComment(ReviewRun $run, string $body): void
    {
        try {
            if ($run->github_comment_id !== null && $this->client->updateIssueComment($run->pullRequest, $run->github_comment_id, $body)) {
                $run->save();

                return;
            }

            $run->update(['github_comment_id' => $this->client->createIssueComment($run->pullRequest, $body)['id'] ?? null]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function heading(ReviewRun $run): string
    {
        return $run->agent !== null ? "## 🫏 Donkey · {$run->agent->name} agent" : '## 🫏 Donkey review';
    }

    /**
     * @return array{path: string, line: int, start_line?: int, side: string, start_side?: string, body: string}
     */
    private function comment(ReviewFinding $finding): array
    {
        $comment = [
            'path' => $finding->file_path,
            'line' => $finding->end_line,
            'side' => 'RIGHT',
            'body' => $this->commentBody($finding),
        ];

        if ($finding->start_line !== $finding->end_line) {
            $comment['start_line'] = $finding->start_line;
            $comment['start_side'] = 'RIGHT';
        }

        return $comment;
    }

    public function commentBody(ReviewFinding $finding): string
    {
        $lines = [];

        if ($finding->agent !== null) {
            $lines[] = "<sub>🤖 {$finding->agent->name} agent</sub>";
            $lines[] = '';
        }

        $lines = [
            ...$lines,
            "**{$finding->severity->emoji()} {$finding->severity->label()} · {$finding->category->label()}** — {$finding->title}",
            '',
            $finding->body,
        ];

        if ($finding->suggestion !== null) {
            $lines[] = '';
            $lines[] = '```suggestion';
            $lines[] = $finding->suggestion;
            $lines[] = '```';
        }

        $meta = ["Risk {$finding->risk_score}/100", "confidence {$finding->confidence}%"];

        if ($finding->graph_callers !== null && $finding->graph_callers > 0) {
            $meta[] = "used by {$finding->graph_callers} ".str('caller')->plural($finding->graph_callers);
        }

        $lines[] = '';
        $lines[] = '<sub>'.implode(' · ', $meta).'</sub>';

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, ReviewFinding>  $findings
     */
    private function summaryBody(ReviewRun $run, Collection $findings, string $summary): string
    {
        $handle = $run->pullRequest->repository->organization->bot_handle;
        $published = $findings->where('status', FindingStatus::Published)->count();
        $suppressed = $findings->where('status', FindingStatus::Suppressed)->count();

        $lines = [$this->heading($run), ''];

        if (trim($summary) !== '') {
            $lines[] = trim($summary);
            $lines[] = '';
        }

        $lines[] = $published === 0
            ? '✅ No issues above the risk threshold.'
            : "Found **{$published}** ".str('issue')->plural($published).' worth a look (see inline comments).';

        $graph = $run->graph_summary;

        if ($run->graph_status === RunGraphStatus::Ready && is_array($graph)) {
            $changed = count($graph['changed_functions'] ?? []);
            $lines[] = '';
            $lines[] = '**Impact** · '.implode(' · ', array_filter([
                "{$changed} changed ".str('function')->plural($changed),
                ($graph['impacted_functions'] ?? 0) > 0 ? "{$graph['impacted_functions']} impacted in {$graph['impacted_files']} ".str('file')->plural((int) $graph['impacted_files']) : null,
                ($graph['test_gaps'] ?? 0) > 0 ? "{$graph['test_gaps']} without tests" : null,
                $run->graph_risk_level !== null ? "structural risk {$run->graph_risk_level}" : null,
            ]));
        }

        if ($run->diff_metrics !== null) {
            $metrics = $run->diff_metrics;
            $lines[] = '';
            $lines[] = "**Diff** · {$run->files_reviewed} ".str('file')->plural($run->files_reviewed)." · +{$metrics['lines_added']} −{$metrics['lines_removed']}"
                .($metrics['comment_lines'] > 0 ? " · {$metrics['comment_lines']} comment ".str('line')->plural($metrics['comment_lines']).' added ('.round(100 * $metrics['comment_lines'] / $metrics['lines_added']).'%)' : '');
        }

        if ($suppressed > 0) {
            $lines[] = '';
            $lines[] = "<sub>{$suppressed} lower-risk ".str('finding')->plural($suppressed)." below the threshold ({$run->risk_threshold}) {$this->verb($suppressed)} kept in the dashboard.</sub>";
        }

        $lines[] = '';
        $lines[] = "<sub>Risk score {$run->risk_score}/100 · Comment `@{$handle} review` after new commits, `@{$handle} full review` to start over, or `@{$handle} help` for more.</sub>";

        return implode("\n", $lines);
    }

    private function verb(int $count): string
    {
        return $count === 1 ? 'is' : 'are';
    }

    /**
     * @param  Collection<int, ReviewFinding>  $findings
     */
    private function inlineFallback(Collection $findings): string
    {
        return $findings
            ->map(fn (ReviewFinding $finding): string => "- `{$finding->file_path}` {$finding->lineLabel()} — {$finding->severity->emoji()} **{$finding->title}**")
            ->implode("\n");
    }

    /**
     * GitHub's review comment listing sometimes omits the line, in which case path and order decide.
     *
     * @param  array<string, mixed>  $comment
     */
    private function isOnLine(ReviewFinding $finding, array $comment): bool
    {
        $line = $comment['line'] ?? $comment['original_line'] ?? null;

        return $line === null || $finding->end_line === $line;
    }

    /**
     * Store GitHub comment ids on findings, matching on path and line.
     *
     * @param  Collection<int, ReviewFinding>  $findings
     */
    private function linkComments(ReviewRun $run, Collection $findings, int $reviewId): void
    {
        try {
            $comments = $this->client->reviewComments($run->pullRequest, $reviewId);
        } catch (RequestException) {
            return;
        }

        foreach ($comments as $comment) {
            $finding = $findings->first(fn (ReviewFinding $finding): bool => $finding->github_comment_id === null
                && $finding->file_path === ($comment['path'] ?? null)
                && $this->isOnLine($finding, $comment));

            $finding?->update(['github_comment_id' => $comment['id']]);
        }
    }
}
