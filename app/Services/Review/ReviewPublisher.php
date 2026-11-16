<?php

namespace App\Services\Review;

use App\Enums\FindingStatus;
use App\Enums\RunGraphStatus;
use App\Models\ReviewFinding;
use App\Models\ReviewRun;
use App\Services\GitHub\PullRequestClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;

/**
 * Turns stored findings into a GitHub review with inline comments and suggestions.
 */
class ReviewPublisher
{
    public function __construct(private PullRequestClient $client) {}

    /**
     * @param  Collection<int, ReviewFinding>  $findings  Every finding of the run, whatever its status.
     */
    public function publish(ReviewRun $run, Collection $findings, string $summary): void
    {
        $toPublish = $findings->where('status', FindingStatus::Published)->values();

        if ($toPublish->isEmpty() && ! $this->shouldPostEmptyReview($run)) {
            return;
        }

        $body = $this->summaryBody($run, $findings, $summary);
        $pullRequest = $run->pullRequest;

        try {
            $review = $this->client->createReview($pullRequest, $run->head_sha, $body, $toPublish->map(fn (ReviewFinding $finding): array => $this->comment($finding))->all());
        } catch (RequestException $exception) {
            if ($exception->response->status() !== 422 || $toPublish->isEmpty()) {
                throw $exception;
            }

            // GitHub refused at least one inline position: fall back to listing findings in the review body.
            $review = $this->client->createReview($pullRequest, $run->head_sha, $body."\n\n".$this->inlineFallback($toPublish), []);

            $run->update(['github_review_id' => $review['id'] ?? null]);

            return;
        }

        $run->update(['github_review_id' => $review['id'] ?? null]);

        if ($toPublish->isNotEmpty() && isset($review['id'])) {
            $this->linkComments($run, $toPublish, (int) $review['id']);
        }
    }

    /**
     * The first review of a pull request always gets a summary; later clean runs stay quiet.
     */
    private function shouldPostEmptyReview(ReviewRun $run): bool
    {
        return $run->pullRequest->last_reviewed_sha === null;
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
        $lines = [
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

        $lines = ['## 🫏 Donkey review', ''];

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

        if ($suppressed > 0) {
            $lines[] = '';
            $lines[] = "<sub>{$suppressed} lower-risk ".str('finding')->plural($suppressed)." below the threshold ({$run->risk_threshold}) {$this->verb($suppressed)} kept in the dashboard.</sub>";
        }

        $lines[] = '';
        $lines[] = "<sub>Risk score {$run->risk_score}/100 · Comment `@{$handle} review` after new commits or `@{$handle} full review` to start over.</sub>";

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
                && $finding->end_line === ($comment['line'] ?? $comment['original_line'] ?? null));

            $finding?->update(['github_comment_id' => $comment['id']]);
        }
    }
}
