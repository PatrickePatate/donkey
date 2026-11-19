<?php

use App\Jobs\ProcessGitHubWebhook;
use App\Models\GithubInstallation;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\WebhookEvent;

require_once __DIR__.'/../GitHub/GitHubAppTestHelpers.php';

/**
 * Pull request #7 on acme/app as GitHub sends it in webhooks.
 *
 * @return array<string, mixed>
 */
function conversationPullRequestPayload(PullRequest $pullRequest): array
{
    return [
        'id' => $pullRequest->github_id,
        'number' => $pullRequest->number,
        'title' => $pullRequest->title,
        'body' => $pullRequest->body,
        'state' => 'open',
        'draft' => false,
        'merged_at' => null,
        'html_url' => $pullRequest->html_url,
        'user' => ['login' => $pullRequest->author_login],
        'head' => ['sha' => $pullRequest->head_sha, 'ref' => $pullRequest->head_ref],
        'base' => ['sha' => $pullRequest->base_sha, 'ref' => $pullRequest->base_ref],
    ];
}

/**
 * Deliver a comment in the pull request conversation.
 */
function deliverIssueComment(GithubInstallation $installation, Repository $repository, string $body, string $association = 'MEMBER', int $commentId = 555): WebhookEvent
{
    return deliverConversationEvent('issue_comment', $installation, [
        'action' => 'created',
        'repository' => ['id' => $repository->github_id],
        'issue' => ['number' => 7, 'pull_request' => ['url' => 'https://api.github.com/repos/acme/app/pulls/7']],
        'comment' => ['id' => $commentId, 'body' => $body, 'author_association' => $association, 'user' => ['login' => 'grace']],
        'sender' => ['login' => 'grace', 'type' => 'User'],
    ]);
}

/**
 * Deliver a reply in an inline review thread started by comment $threadCommentId.
 */
function deliverThreadReply(GithubInstallation $installation, PullRequest $pullRequest, int $threadCommentId, string $body, int $commentId = 556, string $association = 'MEMBER'): WebhookEvent
{
    return deliverConversationEvent('pull_request_review_comment', $installation, [
        'action' => 'created',
        'repository' => ['id' => $pullRequest->repository->github_id],
        'pull_request' => conversationPullRequestPayload($pullRequest),
        'comment' => [
            'id' => $commentId,
            'in_reply_to_id' => $threadCommentId,
            'body' => $body,
            'author_association' => $association,
            'user' => ['login' => 'grace'],
            'path' => 'app/Billing.php',
            'line' => 7,
        ],
        'sender' => ['login' => 'grace', 'type' => 'User'],
    ]);
}

function deliverConversationEvent(string $event, GithubInstallation $installation, array $payload): WebhookEvent
{
    $webhookEvent = WebhookEvent::factory()->create([
        'event' => $event,
        'action' => $payload['action'],
        'installation_id' => $installation->installation_id,
        'payload' => $payload,
    ]);

    (new ProcessGitHubWebhook($webhookEvent))->handle();

    return $webhookEvent->fresh();
}
