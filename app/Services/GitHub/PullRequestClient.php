<?php

namespace App\Services\GitHub;

use App\Models\PullRequest;
use App\Models\Repository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;

/**
 * Pull request operations performed as the GitHub App installation.
 */
class PullRequestClient
{
    public function __construct(private GitHubApp $github)
    {
    }

    /**
     * Files changed by the pull request, with their unified patches.
     *
     * @return list<array<string, mixed>>
     */
    public function files(PullRequest $pullRequest): array
    {
        $files = [];
        $page = 1;

        do {
            $batch = $this->request($pullRequest)
                ->get($this->repoPath($pullRequest)."/pulls/{$pullRequest->number}/files", ['per_page' => 100, 'page' => $page])
                ->throw()
                ->json();

            $files = [...$files, ...$batch];
            $page++;
        } while (count($batch) === 100 && $page <= 30);

        return $files;
    }

    /**
     * Compare two commits. Status is "ahead" when head simply extends base, "diverged" after a force-push.
     *
     * @return array{status: string, files: list<array<string, mixed>>}
     */
    public function compare(PullRequest $pullRequest, string $base, string $head): array
    {
        $response = $this->request($pullRequest)
            ->get($this->repoPath($pullRequest)."/compare/{$base}...{$head}")
            ->throw()
            ->json();

        return [
            'status' => (string) ($response['status'] ?? 'diverged'),
            'files' => $response['files'] ?? [],
        ];
    }

    /**
     * Raw content of a file at a ref, or null when it does not exist.
     */
    public function fileContent(PullRequest $pullRequest, string $path, string $ref): ?string
    {
        return $this->repositoryFileContent($pullRequest->repository, $path, $ref);
    }

    /**
     * Raw content of a repository file at a ref, or null when it does not exist.
     */
    public function repositoryFileContent(Repository $repository, string $path, string $ref): ?string
    {
        $response = $this->repositoryRequest($repository)
            ->accept('application/vnd.github.raw+json')
            ->get($this->repositoryPath($repository).'/contents/'.implode('/', array_map('rawurlencode', explode('/', $path))), ['ref' => $ref]);

        if ($response->status() === 404) {
            return null;
        }

        return $response->throw()->body();
    }

    /**
     * Create a review with inline comments.
     *
     * @param  list<array{path: string, line: int, start_line?: int, side: string, start_side?: string, body: string}>  $comments
     * @return array<string, mixed>
     */
    public function createReview(PullRequest $pullRequest, string $commitId, string $body, array $comments): array
    {
        return $this->request($pullRequest)
            ->post($this->repoPath($pullRequest)."/pulls/{$pullRequest->number}/reviews", [
                'commit_id' => $commitId,
                'body' => $body,
                'event' => 'COMMENT',
                'comments' => $comments,
            ])
            ->throw()
            ->json();
    }

    /**
     * Inline comments created by a review, in the order GitHub stored them.
     *
     * @return list<array<string, mixed>>
     */
    public function reviewComments(PullRequest $pullRequest, int $reviewId): array
    {
        return $this->request($pullRequest)
            ->get($this->repoPath($pullRequest)."/pulls/{$pullRequest->number}/reviews/{$reviewId}/comments", ['per_page' => 100])
            ->throw()
            ->json();
    }

    public function createCheckRun(PullRequest $pullRequest, string $name, string $headSha, string $detailsUrl): ?int
    {
        try {
            return $this->request($pullRequest)
                ->post($this->repoPath($pullRequest).'/check-runs', [
                    'name' => $name,
                    'head_sha' => $headSha,
                    'status' => 'in_progress',
                    'details_url' => $detailsUrl,
                    'started_at' => now()->toIso8601String(),
                ])
                ->throw()
                ->json('id');
        } catch (RequestException) {
            return null;
        }
    }

    /**
     * @param  'success'|'neutral'|'failure'|'cancelled'|'skipped'  $conclusion
     */
    public function completeCheckRun(PullRequest $pullRequest, int $checkRunId, string $conclusion, string $title, string $summary): void
    {
        $this->request($pullRequest)
            ->patch($this->repoPath($pullRequest)."/check-runs/{$checkRunId}", [
                'status' => 'completed',
                'conclusion' => $conclusion,
                'completed_at' => now()->toIso8601String(),
                'output' => ['title' => $title, 'summary' => $summary],
            ]);
    }

    public function reactToComment(PullRequest $pullRequest, int $commentId, string $content): void
    {
        $this->request($pullRequest)
            ->post($this->repoPath($pullRequest)."/issues/comments/{$commentId}/reactions", ['content' => $content]);
    }

    public function reactToReviewComment(PullRequest $pullRequest, int $commentId, string $content): void
    {
        $this->request($pullRequest)
            ->post($this->repoPath($pullRequest)."/pulls/comments/{$commentId}/reactions", ['content' => $content]);
    }

    /**
     * Whether the user can push to the pull request's repository. Webhook payloads label private org members
     * as CONTRIBUTOR on review comments, so this asks GitHub for the real permission instead.
     */
    public function hasWriteAccess(PullRequest $pullRequest, string $login): bool
    {
        $response = $this->request($pullRequest)
            ->get($this->repoPath($pullRequest).'/collaborators/'.rawurlencode($login).'/permission');

        return $response->successful() && in_array($response->json('permission'), ['admin', 'maintain', 'write'], true);
    }

    /**
     * Comment on the pull request conversation.
     *
     * @return array<string, mixed>
     */
    public function createIssueComment(PullRequest $pullRequest, string $body): array
    {
        return $this->request($pullRequest)
            ->post($this->repoPath($pullRequest)."/issues/{$pullRequest->number}/comments", ['body' => $body])
            ->throw()
            ->json();
    }

    /**
     * Edit a comment of the pull request conversation; false when it was deleted.
     */
    public function updateIssueComment(PullRequest $pullRequest, int $commentId, string $body): bool
    {
        $response = $this->request($pullRequest)->patch($this->repoPath($pullRequest)."/issues/comments/{$commentId}", ['body' => $body]);

        if ($response->status() === 404) {
            return false;
        }

        $response->throw();

        return true;
    }

    /**
     * Reply in an inline review thread. GitHub only accepts the thread's top-level comment id.
     *
     * @return array<string, mixed>
     */
    public function replyToReviewComment(PullRequest $pullRequest, int $threadCommentId, string $body): array
    {
        return $this->request($pullRequest)
            ->post($this->repoPath($pullRequest)."/pulls/{$pullRequest->number}/comments/{$threadCommentId}/replies", ['body' => $body])
            ->throw()
            ->json();
    }

    /**
     * One inline review comment, with its reaction counts, or null when it was deleted.
     *
     * @return ?array<string, mixed>
     */
    public function reviewComment(PullRequest $pullRequest, int $commentId): ?array
    {
        $response = $this->request($pullRequest)->get($this->repoPath($pullRequest)."/pulls/comments/{$commentId}");

        if ($response->status() === 404) {
            return null;
        }

        return $response->throw()->json();
    }

    /**
     * Every comment of an inline review thread, oldest first, starting with the top-level comment.
     *
     * @return list<array<string, mixed>>
     */
    public function reviewThread(PullRequest $pullRequest, int $threadCommentId): array
    {
        $thread = [];
        $page = 1;

        do {
            $batch = $this->request($pullRequest)
                ->get($this->repoPath($pullRequest)."/pulls/{$pullRequest->number}/comments", ['per_page' => 100, 'page' => $page, 'sort' => 'created', 'direction' => 'asc'])
                ->throw()
                ->json();

            foreach ($batch as $comment) {
                if (($comment['id'] ?? null) === $threadCommentId || ($comment['in_reply_to_id'] ?? null) === $threadCommentId) {
                    $thread[] = $comment;
                }
            }

            $page++;
        } while (count($batch) === 100 && $page <= 10);

        return $thread;
    }

    /**
     * Fetch a pull request by number, e.g. when a comment arrives before we have seen the PR.
     *
     * @return array<string, mixed>
     */
    public function get(Repository $repository, int $number): array
    {
        return $this->github->asInstallation($repository->installation->installation_id)
            ->get("/repos/{$repository->full_name}/pulls/{$number}")
            ->throw()
            ->json();
    }

    /**
     * Latest commit sha of a branch.
     */
    public function branchHead(Repository $repository, string $branch): string
    {
        return (string) $this->repositoryRequest($repository)
            ->get($this->repositoryPath($repository).'/commits/'.rawurlencode($branch))
            ->throw()
            ->json('sha');
    }

    /**
     * Paths of blob entries at a commit, in the order GitHub returned them.
     *
     * @return list<string>
     */
    public function tree(Repository $repository, string $sha): array
    {
        $response = $this->repositoryRequest($repository)
            ->get($this->repositoryPath($repository)."/git/trees/{$sha}", ['recursive' => 1])
            ->throw()
            ->json();

        $paths = [];

        foreach ($response['tree'] ?? [] as $entry) {
            if (($entry['type'] ?? null) === 'blob' && is_string($entry['path'] ?? null)) {
                $paths[] = $entry['path'];
            }
        }

        return $paths;
    }

    /**
     * Writes one file on a branch cut from the default branch and opens (or reuses) a pull request for it.
     *
     * @return string The pull request's web URL.
     */
    public function proposeFile(Repository $repository, string $branch, string $path, string $content, string $title, string $body): string
    {
        $request = fn (): PendingRequest => $this->repositoryRequest($repository);
        $repoPath = $this->repositoryPath($repository);
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));

        $created = $request()->post("{$repoPath}/git/refs", [
            'ref' => "refs/heads/{$branch}",
            'sha' => $this->branchHead($repository, $repository->default_branch),
        ]);

        // 422 means the branch already exists from an earlier proposal: update it in place.
        if ($created->status() !== 422) {
            $created->throw();
        }

        $existingSha = $request()->get("{$repoPath}/contents/{$encodedPath}", ['ref' => $branch])->json('sha');

        $request()->put("{$repoPath}/contents/{$encodedPath}", array_filter([
            'message' => $title,
            'content' => base64_encode($content),
            'branch' => $branch,
            'sha' => $existingSha,
        ]))->throw();

        $owner = Str::before($repository->full_name, '/');
        $open = $request()->get("{$repoPath}/pulls", ['head' => "{$owner}:{$branch}", 'state' => 'open'])->throw()->json('0.html_url');

        return $open ?? (string) $request()->post("{$repoPath}/pulls", [
            'title' => $title,
            'body' => $body,
            'head' => $branch,
            'base' => $repository->default_branch,
        ])->throw()->json('html_url');
    }

    private function request(PullRequest $pullRequest): PendingRequest
    {
        return $this->repositoryRequest($pullRequest->repository);
    }

    private function repositoryRequest(Repository $repository): PendingRequest
    {
        return $this->github->asInstallation($repository->installation->installation_id);
    }

    private function repositoryPath(Repository $repository): string
    {
        return '/repos/'.$repository->full_name;
    }

    private function repoPath(PullRequest $pullRequest): string
    {
        return '/repos/'.$pullRequest->repository->full_name;
    }
}
