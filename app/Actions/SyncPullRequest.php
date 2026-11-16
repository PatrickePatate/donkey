<?php

namespace App\Actions;

use App\Models\PullRequest;
use App\Models\Repository;

/**
 * Mirrors a GitHub pull request payload into our database.
 */
class SyncPullRequest
{
    /**
     * @param  array<string, mixed>  $payload  The "pull_request" object from a webhook or the REST API.
     */
    public function handle(Repository $repository, array $payload): PullRequest
    {
        return $repository->pullRequests()->updateOrCreate(
            ['number' => $payload['number']],
            [
                'github_id' => $payload['id'],
                'title' => mb_substr((string) $payload['title'], 0, 255),
                'body' => $payload['body'] ?? null,
                'author_login' => $payload['user']['login'] ?? 'unknown',
                'state' => ($payload['merged_at'] ?? null) !== null ? 'merged' : $payload['state'],
                'is_draft' => (bool) ($payload['draft'] ?? false),
                'head_sha' => $payload['head']['sha'],
                'base_sha' => $payload['base']['sha'],
                'head_ref' => $payload['head']['ref'],
                'base_ref' => $payload['base']['ref'],
                'html_url' => $payload['html_url'],
            ],
        );
    }
}
